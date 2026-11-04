<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentChallengeStatus;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Service\QuizTournament\QuizTournamentDuelStarter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A challenge's life (design/validated/quiz-tournament.md §4 and §7), through the screens: « Défier »
 * and its sheet, « Mes défis » and its counter, refusing, accepting, the lazy expiry, the room's
 * « Prêt » and the duel it starts - once. Every rule is read again server-side, whatever the page
 * offered; the rules themselves are pinned on primitives in tests/Service/QuizTournament/.
 */
class QuizTournamentChallengesTest extends FunctionalTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private User $alice;
    private User $bruno;
    private User $chloe;
    private User $outsider;
    private Program $program;
    private QuizTournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'qtc.admin');
        $this->alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'alice');
        $this->bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'bruno');
        $this->chloe = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'chloe');
        $this->outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'dora');
        $this->program = $this->createProgram([$this->alice, $this->bruno, $this->chloe], [], $this->admin);
        $this->createProgram([$this->outsider], [], $this->admin);

        $this->tournament = new QuizTournament('Tournoi RGPD', new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('+2 months'), QuizTournamentPalette::Lavender, $this->admin);
        $this->tournament->addTargetProgram($this->program);
        $this->tournament->addSource($this->quiz('Repérer', qcm: 14, trueFalse: 6, numeric: 2));
        $this->tournament->addSource($this->quiz('Collecter', qcm: 8, trueFalse: 2, numeric: 0));
        $this->tournament->open($this->admin, new \DateTimeImmutable('-1 month'));
        $this->em->persist($this->tournament);
        $this->em->flush();
    }

    public function testAChallengeIsSentFromTheStandingsAndFoundInMesDefis(): void
    {
        $this->client->loginUser($this->alice);
        $crawler = $this->client->request('GET', $this->page());
        self::assertResponseIsSuccessful();
        $button = $crawler->filter(\sprintf('.qt-defy[data-quiz-tournament-sheet-url-param$="/new/%d"]', $this->bruno->getId()));
        self::assertCount(1, $button);
        self::assertStringNotContainsString('is-greyed', (string) $button->attr('class'));

        $this->client->request('GET', $this->page().'/challenges/new/'.$this->bruno->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qt-sheet__title', 'Défier Bruno Test');
        self::assertSelectorTextContains('.qt-stake--win', '+20');
        self::assertSelectorTextContains('.qt-stake--loss', '−20');
        self::assertSelectorTextContains('.qt-reminders', "Entre vous aujourd'hui : 0 défi sur 3.");

        $this->send($this->bruno);
        self::assertResponseRedirects($this->page().'/challenges');
        $challenge = $this->em->getRepository(QuizTournamentChallenge::class)->findOneBy(['challenger' => $this->alice]);
        self::assertInstanceOf(QuizTournamentChallenge::class, $challenge);
        self::assertSame(QuizTournamentChallengeStatus::Pending, $challenge->getStatus());
        self::assertTrue($challenge->isLadder(), 'nobody is ranked yet: the ladder rule holds by itself');

        $this->client->followRedirect();
        self::assertSelectorTextContains('#qt-sent-head', 'Envoyé · 1');
        self::assertSelectorTextContains('.qt-card-sent', 'En attente de sa réponse');

        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->page().'/challenges');
        self::assertSelectorTextContains('.qt-card-challenge', 'Alice Test te défie');
        self::assertSelectorTextContains('.qt-tab.is-active', "Défis\u{a0}(1)");
    }

    public function testAChallengeIsRefusedWhenTheRulesSaySo(): void
    {
        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page());

        // Oneself, and somebody the tournament does not reach.
        $this->send($this->alice);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Tu ne peux pas te défier toi-même.');
        $this->send($this->outsider);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Dora ne peut plus jouer à ce tournoi.');
        self::assertSame(0, $this->em->getRepository(QuizTournamentChallenge::class)->count([]));

        // One outgoing challenge at a time.
        $this->send($this->bruno);
        $this->client->followRedirect();
        $this->send($this->chloe);
        self::assertResponseRedirects($this->page());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Tu as déjà un défi en attente de réponse.');
        self::assertSame(1, $this->em->getRepository(QuizTournamentChallenge::class)->count([]));

        // And the button says it before anybody clicks.
        $button = $this->client->getCrawler()->filter(\sprintf('.qt-defy[data-quiz-tournament-sheet-url-param$="/new/%d"]', $this->chloe->getId()));
        self::assertStringContainsString('is-greyed', (string) $button->attr('class'));
        self::assertSame('Tu as déjà un défi en attente de réponse.', $button->attr('title'));

        // A student the tournament does not reach has no sheet to open.
        $this->client->loginUser($this->outsider);
        $this->client->request('GET', $this->page().'/challenges/new/'.$this->bruno->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testARefusalKeepsTheChallengerAwayFor48Hours(): void
    {
        $challenge = $this->challenge($this->alice, $this->bruno, '-1 hour');

        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->page().'/challenges');
        $this->client->request('POST', $this->page().'/challenges/'.$challenge->getId().'/refuse', ['_token' => $this->csrfToken('quiz_tournament_answer'.$challenge->getId())]);
        self::assertResponseRedirects($this->page().'/challenges');
        self::assertSame(QuizTournamentChallengeStatus::Refused, $this->fresh($challenge)->getStatus());

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page().'/challenges/new/'.$this->bruno->getId());
        self::assertSelectorTextContains('.qt-sheet__refusal', 'Bruno a refusé ton défi il y a moins de 48 h.');
        self::assertSelectorNotExists('.qt-sheet__actions button[type="submit"]');

        $this->send($this->bruno);
        self::assertSame(1, $this->em->getRepository(QuizTournamentChallenge::class)->count([]));
    }

    public function testTwoPlayersMeetThreeTimesADayAtMost(): void
    {
        // Refused by Bruno, expired: whatever became of them, three encounters today. Alice never
        // refused Bruno, so the 48 h of a refusal do not stand in the way of his challenge.
        $this->challenge($this->alice, $this->bruno, 'now')->refuse(new \DateTimeImmutable());
        $this->challenge($this->alice, $this->bruno, 'now')->expire();
        $this->challenge($this->bruno, $this->alice, 'now')->expire();
        $this->em->flush();

        $this->client->loginUser($this->chloe);
        $this->client->request('GET', $this->page());
        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->page());
        $this->send($this->alice);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', "Vous vous êtes déjà défiés 3 fois aujourd'hui.");
    }

    public function testNobodyHasMoreThanFivePendingChallenges(): void
    {
        $others = [];
        for ($i = 0; $i < 5; ++$i) {
            $other = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'student'.$i);
            $this->program->addStudent($other);
            $others[] = $other;
        }
        $this->em->flush();
        foreach ($others as $other) {
            $this->challenge($other, $this->bruno, '-10 minutes');
        }

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page());
        $this->send($this->bruno);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Bruno a déjà trop de défis en attente.');
    }

    public function testAnUnansweredChallengeExpiresWhenItIsReadAndCannotBeAccepted(): void
    {
        $lapsed = $this->challenge($this->alice, $this->bruno, '-25 hours');

        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->page().'/challenges');
        self::assertSelectorNotExists('.qt-card-challenge');
        self::assertSelectorTextContains('.qt-tab.is-active', 'Défis');
        self::assertSelectorNotExists('.qt-tab__count:not([hidden])');
        self::assertSame(QuizTournamentChallengeStatus::Expired, $this->fresh($lapsed)->getStatus(), 'the row is rewritten as it is read');

        $this->client->request('POST', $this->page().'/challenges/'.$lapsed->getId().'/accept', ['_token' => $this->csrfToken('quiz_tournament_answer'.$lapsed->getId())]);
        self::assertResponseRedirects($this->page().'/challenges');
        self::assertSame(QuizTournamentChallengeStatus::Expired, $this->fresh($lapsed)->getStatus());

        // Alice sees its outcome for a day.
        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page().'/challenges');
        self::assertSelectorTextContains('.qt-card-sent', 'Expiré');
    }

    public function testAcceptingOpensTheRoomForBoth(): void
    {
        $challenge = $this->challenge($this->alice, $this->bruno, '-1 hour');

        $this->client->loginUser($this->chloe);
        $this->client->request('GET', $this->page());
        $this->client->request('POST', $this->page().'/challenges/'.$challenge->getId().'/accept', ['_token' => $this->csrfToken('quiz_tournament_answer'.$challenge->getId())]);
        self::assertResponseStatusCodeSame(404, 'only the challenged answers');

        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->page().'/challenges');
        $this->client->request('POST', $this->page().'/challenges/'.$challenge->getId().'/accept', ['_token' => $this->csrfToken('quiz_tournament_answer'.$challenge->getId())]);
        self::assertResponseRedirects($this->room($challenge));
        $challenge = $this->fresh($challenge);
        self::assertSame(QuizTournamentChallengeStatus::Accepted, $challenge->getStatus());
        self::assertEqualsWithDelta((new \DateTimeImmutable('+24 hours'))->getTimestamp(), $challenge->getRoomClosesAt()?->getTimestamp(), 5);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qt-head__title', 'Salle du duel');
        self::assertSelectorTextContains('.qt-player--me', 'Bruno Test (toi)');

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page().'/challenges');
        self::assertSelectorTextContains('#qt-rooms-head', 'Salle ouverte · 1');
        self::assertSelectorTextContains('.qt-tab.is-active', "Défis\u{a0}(1)");

        $this->client->loginUser($this->chloe);
        $this->client->request('GET', $this->room($challenge));
        self::assertResponseStatusCodeSame(404, 'a room is its two players\' alone');
    }

    public function testBothReadyStartTheDuelOnceWithItsFrozenQuestions(): void
    {
        $challenge = $this->challenge($this->alice, $this->bruno, '-2 hours')->accept(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $first = $this->ready($this->alice, $challenge);
        self::assertTrue($first['me']);
        self::assertFalse($first['opponent']);
        self::assertNull($first['duelUrl']);

        // Bruno's « Prêt » finds Alice ready: his own request starts the duel.
        $second = $this->ready($this->bruno, $challenge);
        self::assertIsString($second['duelUrl']);

        $duels = $this->em()->getRepository(QuizTournamentDuel::class)->findAll();
        self::assertCount(1, $duels);
        $duel = $duels[0];
        $challenge = $this->fresh($challenge);
        self::assertSame($this->page().'/duels/'.$duel->getId(), $second['duelUrl']);
        self::assertSame(QuizTournamentChallengeStatus::Played, $challenge->getStatus());
        self::assertSame($duel, $challenge->getDuel());
        self::assertSame($this->alice->getId(), $duel->getPlayerA()->getId(), 'player A is the challenger');
        self::assertSame(QuizTournamentDuelOrigin::Challenge, $duel->getOrigin());
        self::assertSame(1000, $duel->getRatingBeforeA());
        self::assertTrue($duel->isLadderA());

        $questions = $duel->getQuestions();
        self::assertCount(20, $questions);
        $trueFalse = 0;
        foreach ($questions as $rank => $question) {
            self::assertSame($rank, $question->getRank());
            self::assertNotNull($question->getSourceQuestion());
            self::assertContains($question->getSourceQuizName(), ['Repérer', 'Collecter']);
            self::assertNull($question->getServedAt(), 'the duel screen serves each question');
            if (QuestionType::VraiFaux === $question->getType()) {
                ++$trueFalse;
                self::assertSame(['Vrai', 'Faux'], array_column($question->getChoices(), 'label'));
            } elseif (QuestionType::Numerique === $question->getType()) {
                self::assertSame([], $question->getChoices());
                self::assertSame(42.0, $question->getNumericAnswer());
            } else {
                self::assertCount(4, $question->getChoices());
                self::assertSame(20, $question->getTimeLimitSeconds());
            }
        }
        self::assertLessThanOrEqual(5, $trueFalse);

        // A beat that arrives after the start, a second tab, or a request that waited on the lock
        // with the room read before: the same duel, never a second one.
        $again = $this->ready($this->alice, $challenge);
        self::assertSame($second['duelUrl'], $again['duelUrl']);
        $challenge = $this->fresh($challenge);
        self::assertSame($duel->getId(), static::getContainer()->get(QuizTournamentDuelStarter::class)->startIfReady($challenge, new \DateTimeImmutable())?->getId());
        self::assertCount(1, $this->em()->getRepository(QuizTournamentDuel::class)->findAll());

        // Both pages go to the duel, which only its players reach.
        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->room($challenge));
        self::assertResponseRedirects($second['duelUrl']);
        $this->client->request('GET', $second['duelUrl']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="quiz-tournament-duel"]');
        $this->client->loginUser($this->chloe);
        $this->client->request('GET', $second['duelUrl']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testReadyIsRefusedWhileTheOtherPlaysElsewhereAndLeavingClearsIt(): void
    {
        $challenge = $this->challenge($this->alice, $this->bruno, '-2 hours')->accept(new \DateTimeImmutable('-1 hour'));
        $elsewhere = new QuizTournamentDuel($this->tournament, $this->bruno, $this->chloe, QuizTournamentDuelOrigin::Challenge, 7, new \DateTimeImmutable('-1 minute'), 1000, 1000, false, false);
        $this->em->persist($elsewhere);
        $this->em->flush();

        $state = $this->ready($this->alice, $challenge, 409);
        self::assertFalse($state['me']);
        self::assertSame('Bruno joue déjà un duel : « Prêt » attendra sa fin.', $state['refusal']);

        $this->fresh($elsewhere)->finish(new \DateTimeImmutable(), 10.0, 9.0, 1010, 990);
        $this->em()->flush();
        self::assertTrue($this->ready($this->alice, $challenge)['me']);

        $this->client->request('POST', $this->room($challenge).'/leave', ['_token' => $this->csrfToken('quiz_tournament_room'.$challenge->getId())]);
        self::assertResponseStatusCodeSame(204);
        $alice = $this->fresh($this->alice);
        self::assertFalse($this->fresh($challenge)->isReady($alice, new \DateTimeImmutable()));

        // A « Prêt » left for more than 45 s is gone: Bruno's arrival starts nothing.
        $this->fresh($challenge)->markReady($alice, new \DateTimeImmutable('-50 seconds'));
        $this->em()->flush();
        self::assertNull($this->ready($this->bruno, $challenge)['duelUrl']);
        self::assertSame(QuizTournamentChallengeStatus::Accepted, $this->fresh($challenge)->getStatus());
    }

    public function testTheTournamentsEndClosesTheRoomsAndThePendingChallenges(): void
    {
        $pending = $this->challenge($this->chloe, $this->alice, '-3 hours');
        $room = $this->challenge($this->alice, $this->bruno, '-2 hours')->accept(new \DateTimeImmutable('-1 hour'));
        $this->tournament->setEndsAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page().'/challenges');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qt-sticker', 'Tournoi terminé');
        self::assertSelectorNotExists('.qt-card-room');
        self::assertSelectorNotExists('.qt-card-challenge');
        self::assertSame(QuizTournamentChallengeStatus::Expired, $this->fresh($pending)->getStatus());
        self::assertSame(QuizTournamentChallengeStatus::Missed, $this->fresh($room)->getStatus());

        $this->client->request('GET', $this->room($room));
        self::assertResponseRedirects($this->page().'/challenges');
    }

    /**
     * The test's own entity manager is reset by the kernel between requests: an entity a request
     * changed is read again from the one the application uses now.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(object $entity): object
    {
        \assert(method_exists($entity, 'getId'));
        $fresh = $this->em()->find($entity::class, $entity->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function page(): string
    {
        return '/tournaments/'.$this->tournament->getId();
    }

    private function room(QuizTournamentChallenge $challenge): string
    {
        return $this->page().'/rooms/'.$challenge->getId();
    }

    private function send(User $opponent): void
    {
        $this->client->request('POST', $this->page().'/challenges', [
            '_token' => $this->csrfToken('quiz_tournament_challenge'.$this->tournament->getId()),
            'opponent' => (string) $opponent->getId(),
        ]);
    }

    /**
     * « Prêt » as this player, through the page's own request.
     *
     * @return array<string, mixed>
     */
    private function ready(User $player, QuizTournamentChallenge $challenge, int $expected = 200): array
    {
        $this->client->loginUser($player);
        $this->client->request('GET', $this->page());
        $this->client->request('POST', $this->room($challenge).'/ready', [], [], [
            'HTTP_X-CSRF-Token' => $this->csrfToken('quiz_tournament_room'.$challenge->getId()),
        ]);
        self::assertResponseStatusCodeSame($expected);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    private function challenge(User $challenger, User $challenged, string $sentAt): QuizTournamentChallenge
    {
        $challenge = new QuizTournamentChallenge($this->tournament, $challenger, $challenged, false, new \DateTimeImmutable($sentAt));
        $this->em->persist($challenge);
        $this->em->flush();

        return $challenge;
    }

    private function quiz(string $name, int $qcm, int $trueFalse, int $numeric): QuizTemplate
    {
        $quiz = new QuizTemplate($this->admin);
        $quiz->setName($name);
        $quiz->setCreatedBy($this->admin);
        $this->em->persist($quiz);

        $add = function (QuestionType $type, string $label, array $answers) use ($quiz): QuizQuestion {
            $question = new QuizQuestion($quiz);
            $question->setType($type);
            $question->setLabel($label);
            foreach ($answers as $index => [$text, $correct]) {
                $answer = new QuizAnswer($question);
                $answer->setLabel($text);
                $answer->setIsCorrect($correct);
                $answer->setOrderIndex($index);
                $question->addAnswer($answer);
                $this->em->persist($answer);
            }
            $quiz->addQuestion($question);
            $this->em->persist($question);

            return $question;
        };

        for ($i = 0; $i < $qcm; ++$i) {
            $add(QuestionType::Qcm, $name.' QCM '.$i, [['A', true], ['B', false], ['C', false], ['D', false]]);
        }
        for ($i = 0; $i < $trueFalse; ++$i) {
            $add(QuestionType::VraiFaux, $name.' VF '.$i, [['Vrai', true], ['Faux', false]]);
        }
        for ($i = 0; $i < $numeric; ++$i) {
            $add(QuestionType::Numerique, $name.' Num '.$i, [])->setNumericConfig(['answer' => 42, 'tolerance' => 0, 'toleranceMode' => 'absolute']);
        }
        $this->em->flush();

        return $quiz;
    }
}
