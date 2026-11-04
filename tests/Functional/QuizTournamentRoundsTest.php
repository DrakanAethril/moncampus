<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramStudentOption;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentPlayer;
use App\Entity\QuizTournamentRound;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Enum\QuizTournamentRoundStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A round in class (design/validated/quiz-tournament.md §8), through the screens: the teacher opens
 * it for a class - narrowed or not -, the students it expects join from the tournament's page, the
 * teacher closes and pairs, every duel starts at once and the odd one out sits out; or the round is
 * cancelled. Every rule is read again server-side; the rules themselves are pinned on primitives in
 * tests/Service/QuizTournament/QuizTournamentRoundRulesTest.php.
 */
class QuizTournamentRoundsTest extends FunctionalTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private User $teacher;
    private User $colleague;
    private User $otherTeacher;
    private User $alice;
    private User $bruno;
    private User $chloe;
    private User $dora;
    private Program $program;
    private QuizTournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'qtr.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'teacher');
        $this->colleague = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'colleague');
        $this->otherTeacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'elsewhere');
        $this->alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'alice');
        $this->bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'bruno');
        $this->chloe = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'chloe');
        $this->dora = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'dora');
        $this->program = $this->createProgram([$this->alice, $this->bruno, $this->chloe, $this->dora], [$this->teacher, $this->colleague], $this->admin);
        $this->createProgram([], [$this->otherTeacher], $this->admin);

        $this->tournament = new QuizTournament('Tournoi RGPD', new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('+2 months'), QuizTournamentPalette::Lavender, $this->admin);
        $this->tournament->addTargetProgram($this->program);
        $this->tournament->addSource($this->quiz('Repérer', qcm: 22, trueFalse: 6));
        $this->tournament->open($this->admin, new \DateTimeImmutable('-1 month'));
        $this->em->persist($this->tournament);
        $this->em->flush();
    }

    public function testATeacherOpensARoundTheStudentsJoinAndTheOddOneSitsOut(): void
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $this->page());
        self::assertSelectorTextContains('.qt-round-teacher', 'Lancer une ronde');
        $this->client->request('GET', $this->page().'/rounds/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="who"] option[value="class"]');

        $round = $this->open('class');
        self::assertSame(QuizTournamentRoundStatus::Open, $round->getStatus());
        self::assertResponseRedirects($this->page().'/rounds/'.$round->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.qt-stage__badge', 'Inscriptions ouvertes');
        self::assertSelectorTextContains('.qt-stage__count', '0');
        self::assertSelectorTextContains('.qt-stage__count-of', 'présent sur 4');
        self::assertSelectorTextContains('.qt-stage__path', 'Tournoi RGPD › Rejoindre la ronde');
        self::assertStringNotContainsString('Formatool', (string) $this->client->getResponse()->getContent());

        // A student of the class sees the card, joins, then waits.
        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page());
        self::assertSelectorTextContains('.qt-round-card', 'Rejoindre la ronde');
        $this->join($round, $this->alice);
        self::assertResponseRedirects($this->page());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.qt-round-card', 'Tu as rejoint la ronde');
        self::assertSelectorExists('.qt-round-card[data-beat-url]');
        $this->join($round, $this->bruno);
        $this->join($round, $this->chloe, back: 'challenges');
        self::assertResponseRedirects($this->page().'/challenges');

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $this->page().'/rounds/'.$round->getId().'/board');
        self::assertSelectorTextContains('.qt-stage__count-n', '3');
        self::assertSelectorCount(3, '.qt-stage__sticker:not(.qt-stage__sticker--missing)');
        self::assertSelectorCount(1, '.qt-stage__sticker--missing');
        self::assertSelectorTextContains('.qt-stage__btn--main', 'Clore et apparier les 3');

        $this->close($round);
        self::assertResponseRedirects($this->page().'/rounds/'.$round->getId());
        $round = $this->fresh($round);
        self::assertSame(QuizTournamentRoundStatus::Paired, $round->getStatus());

        $duels = $this->em()->getRepository(QuizTournamentDuel::class)->findBy(['round' => $round]);
        self::assertCount(1, $duels);
        self::assertSame(QuizTournamentDuelOrigin::Round, $duels[0]->getOrigin());
        self::assertCount(20, $duels[0]->getQuestions());
        $exempt = $round->getExempt();
        self::assertInstanceOf(User::class, $exempt);
        self::assertFalse($this->involves($duels[0], $exempt));
        self::assertSame(1, $this->line($exempt)->getRoundExemptions());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.qt-stage__badge', '0 duel terminé sur 1');
        self::assertSelectorCount(1, '.qt-round-duel[data-duel-id]');
        self::assertSelectorTextContains('.qt-round-duel--exempt', 'Nombre impair');

        // The players are sent to their duel; the exempt is told.
        $player = $duels[0]->getPlayerA();
        $this->client->loginUser($player);
        $this->client->request('GET', $this->page());
        self::assertSelectorTextContains('.qt-round-card', 'Ton duel de la ronde a commencé');
        $state = $this->beat($round);
        self::assertSame('playing', $state['state']);
        self::assertSame('/tournaments/'.$this->tournament->getId().'/duels/'.$duels[0]->getId(), $state['duelUrl']);
        $this->client->loginUser($exempt);
        $this->client->request('GET', $this->page());
        self::assertSelectorTextContains('.qt-round-card', 'Pas de duel pour toi cette fois');
    }

    public function testEveryDuelOfARoundStartsAtTheSameMoment(): void
    {
        $round = $this->openAs($this->teacher, 'class');
        foreach ([$this->alice, $this->bruno, $this->chloe, $this->dora] as $student) {
            $this->join($round, $student);
        }
        $this->client->loginUser($this->teacher);
        $this->close($round);

        $duels = $this->em()->getRepository(QuizTournamentDuel::class)->findBy(['round' => $round]);
        self::assertCount(2, $duels);
        self::assertNull($this->fresh($round)->getExempt());
        self::assertEquals($duels[0]->getStartedAt(), $duels[1]->getStartedAt());
    }

    public function testAGroupIsReadOnTheDay(): void
    {
        // Saved when Eve was in the class, and with Gaspard, whose account is a test account the
        // tournament does not reach; Chloé is in the other group.
        $eve = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'eve');
        $gaspard = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'gaspard');
        $gaspard->setTestUser(true);
        $this->program->addStudent($gaspard);
        $batch = new GroupBatch($this->program, $this->colleague, 'TP réseau', [
            [(int) $this->chloe->getId(), (int) $this->dora->getId()],
            [(int) $this->alice->getId(), (int) $this->bruno->getId(), (int) $eve->getId(), (int) $gaspard->getId()],
        ]);
        $batch->addSharedTeacher($this->teacher);
        $this->em->persist($batch);
        $this->em->flush();

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $this->page().'/rounds/new');
        self::assertSelectorExists(\sprintf('select[name="who"] option[value="group:%d:1"]', $batch->getId()));
        self::assertSelectorExists('select[name="who"] optgroup[label="Lot « TP réseau » (partagé par Colleague Test)"]');
        $round = $this->open('group:'.$batch->getId().':1');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.qt-stage__where', 'groupe 2 (lot « TP réseau »)');
        self::assertSelectorTextContains('.qt-stage__count-of', 'présent sur 2');
        self::assertSelectorTextContains('.qt-stage__count-scope', 'dans le groupe 2');

        // Chloé is in the class but not in the group: no card, and no joining by hand.
        $this->client->loginUser($this->chloe);
        $this->client->request('GET', $this->page());
        self::assertSelectorNotExists('.qt-round-card');
        $this->join($round, $this->chloe);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', "Cette ronde n'est pas pour toi.");
        $this->join($round, $gaspard);
        self::assertFalse($this->hasJoined($round, $gaspard));
        $this->join($round, $eve);
        self::assertCount(0, $this->fresh($round)->getParticipants());

        $this->join($round, $this->alice);
        $this->join($round, $this->bruno);
        $this->client->loginUser($this->teacher);
        $this->close($round);
        $duels = $this->em()->getRepository(QuizTournamentDuel::class)->findBy(['round' => $round]);
        self::assertCount(1, $duels);
        self::assertTrue($this->involves($duels[0], $this->alice) && $this->involves($duels[0], $this->bruno));
    }

    public function testAnOptionOfTheClassNarrowsTheRound(): void
    {
        $sisr = new Option('SISR', 'SISR', '#123456');
        $sisr->setCreatedBy($this->admin);
        $sisr->addProgram($this->program);
        $this->em->persist($sisr);
        $foreign = new Option('SLAM', 'SLAM', '#654321');
        $foreign->setCreatedBy($this->admin);
        $this->em->persist($foreign);
        $this->em->persist(new ProgramStudentOption($this->program, $this->bruno, $sisr));
        $this->em->flush();

        // An option that is not the class's is refused, whatever the page offered.
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $this->page().'/rounds/new');
        self::assertSelectorExists(\sprintf('select[name="who"] option[value="option:%d"]', $sisr->getId()));
        $this->open('option:'.$foreign->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', "Cette option n'est pas une option de la classe.");
        self::assertSame(0, $this->em()->getRepository(QuizTournamentRound::class)->count([]));

        $round = $this->open('option:'.$sisr->getId());
        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->page());
        self::assertSelectorTextContains('.qt-round-card__where', 'option SISR');
        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page());
        self::assertSelectorNotExists('.qt-round-card');
        self::assertNotNull($round->getId());
    }

    public function testOneRoundIsOpenPerClassAtATime(): void
    {
        $round = $this->openAs($this->teacher, 'class');

        $this->client->loginUser($this->colleague);
        $this->client->request('GET', $this->page().'/rounds/new');
        self::assertSelectorTextContains('.alert-warning', 'Teacher Test a déjà une ronde ouverte pour cette classe.');
        $this->open('class');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Une ronde est déjà ouverte pour cette classe.');
        self::assertSame(1, $this->em()->getRepository(QuizTournamentRound::class)->count([]));

        // Only its teacher projects it.
        $this->client->request('GET', $this->page().'/rounds/'.$round->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testATeacherOfAnotherClassHasNoRound(): void
    {
        $round = $this->openAs($this->teacher, 'class');

        $this->client->loginUser($this->otherTeacher);
        $this->client->request('GET', $this->page());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->page().'/rounds/new');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->page().'/rounds/'.$round->getId());
        self::assertResponseStatusCodeSame(404);

        // Nor does a student, whatever the address.
        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page().'/rounds/new');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->page().'/rounds/'.$round->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStudentLeavesAndAnAbsentOneIsLeftOut(): void
    {
        $round = $this->openAs($this->teacher, 'class');
        $this->join($round, $this->alice);
        $this->join($round, $this->bruno);
        $this->join($round, $this->chloe);
        $this->join($round, $this->dora);

        // Dora leaves; Chloé's page stopped beating a minute ago.
        $this->client->loginUser($this->dora);
        $this->client->request('POST', $this->page().'/rounds/'.$round->getId().'/leave', ['_token' => $this->csrfToken('quiz_tournament_round_entry'.$round->getId())]);
        self::assertFalse($this->hasJoined($round, $this->dora));
        $this->line($this->chloe)->markSeen(new \DateTimeImmutable('-1 minute'));
        $this->em()->flush();

        $this->client->loginUser($this->teacher);
        $this->client->request('GET', $this->page().'/rounds/'.$round->getId().'/board');
        self::assertSelectorTextContains('.qt-stage__count-n', '2');

        $this->close($round);
        $round = $this->fresh($round);
        $duels = $this->em()->getRepository(QuizTournamentDuel::class)->findBy(['round' => $round]);
        self::assertCount(1, $duels);
        self::assertNull($round->getExempt());
        self::assertFalse($this->involves($duels[0], $this->chloe));

        $this->client->loginUser($this->chloe);
        $this->client->request('GET', $this->page());
        self::assertSelectorTextContains('.qt-round-card', 'La ronde a commencé sans toi');
    }

    public function testAPlayerAlreadyInADuelIsSkipped(): void
    {
        $round = $this->openAs($this->teacher, 'class');
        foreach ([$this->alice, $this->bruno, $this->chloe] as $student) {
            $this->join($round, $student);
        }
        $em = $this->em();
        $elsewhere = new QuizTournamentDuel(
            $em->getReference(QuizTournament::class, $this->tournament->getId()),
            $em->getReference(User::class, $this->bruno->getId()),
            $em->getReference(User::class, $this->dora->getId()),
            QuizTournamentDuelOrigin::Challenge, 1, new \DateTimeImmutable('-1 minute'), 1000, 1000, true, true,
        );
        $em->persist($elsewhere);
        $em->flush();

        $this->client->loginUser($this->teacher);
        $this->close($round);
        $duels = $this->em()->getRepository(QuizTournamentDuel::class)->findBy(['round' => $round]);
        self::assertCount(1, $duels);
        self::assertTrue($this->involves($duels[0], $this->alice) && $this->involves($duels[0], $this->chloe));
        self::assertNull($this->fresh($round)->getExempt());
    }

    public function testClosingNeedsTwoPresentPlayers(): void
    {
        $round = $this->openAs($this->teacher, 'class');
        $this->join($round, $this->alice);

        $this->client->loginUser($this->teacher);
        $this->close($round);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.qt-stage__flash', 'Il faut au moins deux joueurs présents pour apparier.');
        self::assertSame(QuizTournamentRoundStatus::Open, $this->fresh($round)->getStatus());
    }

    public function testARoundIsCancelled(): void
    {
        $round = $this->openAs($this->teacher, 'class');
        $this->join($round, $this->alice);

        $this->client->loginUser($this->teacher);
        $this->client->request('POST', $this->page().'/rounds/'.$round->getId().'/cancel', ['_token' => $this->csrfToken('quiz_tournament_round'.$round->getId())]);
        self::assertResponseRedirects($this->page());
        self::assertSame(QuizTournamentRoundStatus::Cancelled, $this->fresh($round)->getStatus());

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->page());
        self::assertSelectorNotExists('.qt-round-card');
        self::assertSame('none', $this->beat($round)['state']);
        $this->join($round, $this->bruno);
        self::assertFalse($this->hasJoined($round, $this->bruno));
        self::assertSame(0, $this->em()->getRepository(QuizTournamentDuel::class)->count([]));
    }

    private function page(): string
    {
        return '/tournaments/'.$this->tournament->getId();
    }

    /** « Ouvrir la ronde » as the logged-in teacher; answers the round it made, if any. */
    private function open(string $who): QuizTournamentRound
    {
        $this->client->request('POST', $this->page().'/rounds', [
            '_token' => $this->csrfToken('quiz_tournament_round_open'.$this->tournament->getId()),
            'program' => (string) $this->program->getId(),
            'who' => $who,
        ]);
        $round = $this->em()->getRepository(QuizTournamentRound::class)->findOneBy([], ['id' => 'DESC']);

        return $round ?? new QuizTournamentRound($this->tournament, $this->program, $this->teacher, new \DateTimeImmutable());
    }

    private function openAs(User $teacher, string $who): QuizTournamentRound
    {
        $this->client->loginUser($teacher);
        $this->client->request('GET', $this->page());
        $round = $this->open($who);
        self::assertNotNull($round->getId());

        return $round;
    }

    private function join(QuizTournamentRound $round, User $student, string $back = 'standings'): void
    {
        $this->client->loginUser($student);
        $this->client->request('GET', $this->page());
        $this->client->request('POST', $this->page().'/rounds/'.$round->getId().'/join', [
            '_token' => $this->csrfToken('quiz_tournament_round_entry'.$round->getId()),
            'back' => $back,
        ]);
    }

    private function close(QuizTournamentRound $round): void
    {
        $this->client->request('POST', $this->page().'/rounds/'.$round->getId().'/close', ['_token' => $this->csrfToken('quiz_tournament_round'.$round->getId())]);
    }

    /** @return array<string, mixed> */
    private function beat(QuizTournamentRound $round): array
    {
        $this->client->request('POST', $this->page().'/rounds/'.$round->getId().'/beat', [], [], [
            'HTTP_X-CSRF-Token' => $this->csrfToken('quiz_tournament_round_entry'.$round->getId()),
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    private function line(User $user): QuizTournamentPlayer
    {
        $line = $this->em()->getRepository(QuizTournamentPlayer::class)->findOneBy(['tournament' => $this->tournament, 'user' => $user]);
        self::assertNotNull($line);

        return $line;
    }

    /** The round as the database holds it now: each request leaves the entity manager reset. */
    private function fresh(QuizTournamentRound $round): QuizTournamentRound
    {
        $fresh = $this->em()->find(QuizTournamentRound::class, $round->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function involves(QuizTournamentDuel $duel, User $user): bool
    {
        return \in_array($user->getId(), [$duel->getPlayerA()->getId(), $duel->getPlayerB()->getId()], true);
    }

    private function hasJoined(QuizTournamentRound $round, User $user): bool
    {
        return \in_array($user->getId(), array_map(static fn (User $p): ?int => $p->getId(), $this->fresh($round)->getParticipants()->toArray()), true);
    }

    private function quiz(string $name, int $qcm, int $trueFalse): QuizTemplate
    {
        $quiz = new QuizTemplate($this->admin);
        $quiz->setName($name);
        $quiz->setCreatedBy($this->admin);
        $this->em->persist($quiz);

        $add = function (QuestionType $type, string $label, array $answers) use ($quiz): void {
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
        };

        for ($i = 0; $i < $qcm; ++$i) {
            $add(QuestionType::Qcm, $name.' QCM '.$i, [['A', true], ['B', false], ['C', false], ['D', false]]);
        }
        for ($i = 0; $i < $trueFalse; ++$i) {
            $add(QuestionType::VraiFaux, $name.' VF '.$i, [['Vrai', true], ['Faux', false]]);
        }
        $this->em->flush();

        return $quiz;
    }
}
