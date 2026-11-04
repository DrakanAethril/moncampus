<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelAnswer;
use App\Entity\QuizTournamentDuelQuestion;
use App\Entity\QuizTournamentPlayer;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentChallengeStatus;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Service\QuizTournament\QuizTournamentDuelClock;
use App\Service\QuizTournament\QuizTournamentDuelEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A duel as it is played (design/validated/quiz-tournament.md §5.2 to §6.1), through its screens and
 * the scheduled task: two players answering, a question closed early or at its deadline, a reload
 * that restarts no clock, the end - ratings moved once -, the result, the defeat, the correction
 * (only once it is over, only for the two players) and « Mes duels ». The clock and the grading are
 * pinned on primitives in tests/Service/QuizTournament/.
 */
class QuizTournamentDuelTest extends FunctionalTestCase
{
    private EntityManagerInterface $em;
    private User $admin;
    private User $alice;
    private User $bruno;
    private User $chloe;
    private User $teacher;
    private QuizTournament $tournament;
    private QuizTemplate $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'qtd.admin');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'qtd.teacher');
        $this->alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'alice');
        $this->bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'bruno');
        $this->chloe = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'chloe');
        $program = $this->createProgram([$this->alice, $this->bruno, $this->chloe], [$this->teacher], $this->admin);

        $this->tournament = new QuizTournament('Tournoi RGPD', new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('+2 months'), QuizTournamentPalette::Lavender, $this->admin);
        $this->tournament->addTargetProgram($program);
        $this->tournament->open($this->admin, new \DateTimeImmutable('-1 month'));
        $this->em->persist($this->tournament);
        $this->source = new QuizTemplate($this->admin);
        $this->source->setName('Violation de données');
        $this->source->setCreatedBy($this->admin);
        $this->em->persist($this->source);
        $this->em->flush();
    }

    public function testBothAnswersCloseTheQuestionAndNeitherLearnsTheOthersPick(): void
    {
        $duel = $this->duel(QuizTournamentDuelClock::LEAD_SECONDS + 1, 3);

        $state = $this->answer($this->alice, $duel, ['rank' => 0, 'choices' => [1]]);
        self::assertSame('question', $state['phase']);
        self::assertSame(['choices' => [1], 'value' => null, 'unit' => null], $state['mine']);
        self::assertTrue($this->answered($state, $this->alice));
        self::assertNull($this->at($state, 'results', 0), 'nothing is told before the question closes');
        $choice = $this->at($state, 'question', 'choices', 0);
        self::assertIsArray($choice);
        self::assertArrayNotHasKey('correct', $choice, 'the page never gets the correction');

        // Checked while question 0 is still open, 20 s from its deadline: after both answers the 2 s
        // reveal may already be over on a slow runner, and question 1 then rightly takes answers.
        $refused = $this->answer($this->alice, $duel, ['rank' => 0, 'choices' => [2]], 409);
        self::assertSame('Tu as déjà répondu à cette question.', $refused['refusal']);
        $refused = $this->answer($this->alice, $duel, ['rank' => 1, 'choices' => [1]], 409);
        self::assertSame('Trop tard : cette question est close.', $refused['refusal'], 'never the next question before it is served');

        $seen = $this->state($this->bruno, $duel);
        self::assertTrue($this->answered($seen, $this->alice), 'Bruno sees that Alice answered');
        self::assertNull($seen['mine'], '… never what she answered');

        $state = $this->answer($this->bruno, $duel, ['rank' => 0, 'choices' => [2]]);
        self::assertSame('reveal', $state['phase']);
        self::assertSame(1.0, $this->resultOf($state, 0, $this->alice));
        self::assertSame(0.0, $this->resultOf($state, 0, $this->bruno));
        self::assertEqualsWithDelta(1.0, $this->at($state, 'scores', (string) $this->alice->getId()), 0.001);

        self::assertCount(2, $this->em()->getRepository(QuizTournamentDuelAnswer::class)->findAll());
    }

    public function testAnAnswerAfterTheDeadlineIsRefusedAndTheNextQuestionIsServedOnTheClock(): void
    {
        $duel = $this->duel(QuizTournamentDuelClock::LEAD_SECONDS + 20 + 3, 3);
        $startedAt = QuizTournamentDuelClock::second($duel->getStartedAt());

        $refused = $this->answer($this->alice, $duel, ['rank' => 0, 'choices' => [1]], 409);
        self::assertSame('Trop tard : cette question est close.', $refused['refusal']);
        self::assertSame('question', $refused['phase']);
        self::assertSame(1, $refused['rank']);
        self::assertSame([0 => [(string) $this->alice->getId() => null, (string) $this->bruno->getId() => null]], array_filter($refused['results']), 'an unanswered question counts zero');

        $questions = $this->fresh($duel)->getQuestions();
        self::assertEquals($startedAt->modify('+5 seconds'), $questions[0]->getServedAt());
        self::assertEquals($startedAt->modify(\sprintf('+%d seconds', QuizTournamentDuelClock::LEAD_SECONDS + 20 + QuizTournamentDuelClock::TRANSITION_SECONDS)), $questions[1]->getServedAt(), 'served when its time came, not when somebody asked');
        self::assertNull($questions[2]->getServedAt());

        $answered = $this->answer($this->alice, $duel, ['rank' => 1, 'choices' => [1]]);
        self::assertTrue($this->answered($answered, $this->alice));
    }

    public function testReloadingRestartsNoClock(): void
    {
        $duel = $this->duel(QuizTournamentDuelClock::LEAD_SECONDS + 4, 3);

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->url($duel));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="quiz-tournament-duel"]');
        $served = $this->fresh($duel)->getQuestions()[0]->getServedAt();
        self::assertEquals(QuizTournamentDuelClock::second($duel->getStartedAt())->modify('+5 seconds'), $served);

        $this->client->request('GET', $this->url($duel));
        $state = $this->state($this->alice, $duel);
        self::assertEquals($served, $this->fresh($duel)->getQuestions()[0]->getServedAt());
        self::assertSame($served?->format(\DATE_ATOM), $this->at($state, 'question', 'servedAt'));
    }

    public function testTheEndMovesBothRatingsExactlyOnce(): void
    {
        $duel = $this->duel(600, 2);
        $this->answerRow($duel, 0, $this->alice, [1], 1.0);
        $this->answerRow($duel, 1, $this->alice, [1], 1.0);
        $this->answerRow($duel, 0, $this->bruno, [2], 0.0);

        $state = $this->state($this->bruno, $duel);
        self::assertSame('finished', $state['phase']);
        self::assertSame($this->url($duel).'/result', $state['resultUrl']);

        $duel = $this->fresh($duel);
        self::assertTrue($duel->isFinished());
        self::assertSame(2.0, $duel->getScoreA());
        self::assertSame(0.0, $duel->getScoreB());
        self::assertSame(1020, $duel->getRatingAfterA());
        self::assertSame(980, $duel->getRatingAfterB());

        // Again from the screen, the scheduled task, the engine itself: nothing moves twice.
        $this->state($this->alice, $duel);
        $this->closeDuels();
        self::assertFalse(static::getContainer()->get(QuizTournamentDuelEngine::class)->settle($this->fresh($duel), new \DateTimeImmutable()));

        $alice = $this->line($this->alice);
        $bruno = $this->line($this->bruno);
        self::assertSame([1020, 1, 1, 0, 0], [$alice->getRating(), $alice->getDuels(), $alice->getWins(), $alice->getDraws(), $alice->getLosses()]);
        self::assertSame([980, 1, 0, 0, 1], [$bruno->getRating(), $bruno->getDuels(), $bruno->getWins(), $bruno->getDraws(), $bruno->getLosses()]);

        // The duel screen of a finished duel is its result.
        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->url($duel));
        self::assertResponseRedirects($this->url($duel).'/result');
    }

    public function testADuelWithoutQuestionsEndsAtOnceAsADraw(): void
    {
        $duel = $this->duel(0, 0);

        $state = $this->state($this->alice, $duel);
        self::assertSame('finished', $state['phase']);
        $duel = $this->fresh($duel);
        self::assertSame([1000, 1000], [$duel->getRatingAfterA(), $duel->getRatingAfterB()]);
        self::assertSame(1, $this->line($this->alice)->getDraws());
    }

    public function testTheScheduledTaskFinishesAbandonedDuelsAndClosesLapsedChallenges(): void
    {
        $abandoned = $this->duel(600, 3);
        $running = $this->duel(QuizTournamentDuelClock::LEAD_SECONDS + 2, 3, $this->chloe);
        $expired = new QuizTournamentChallenge($this->tournament, $this->chloe, $this->alice, false, new \DateTimeImmutable('-25 hours'));
        $missed = (new QuizTournamentChallenge($this->tournament, $this->bruno, $this->chloe, false, new \DateTimeImmutable('-50 hours')))->accept(new \DateTimeImmutable('-49 hours'));
        $this->em->persist($expired);
        $this->em->persist($missed);
        $this->em->flush();

        $output = $this->closeDuels();
        self::assertStringContainsString('1 duel(s) terminé(s) sur 2', $output);
        $this->em()->clear();

        self::assertTrue($this->fresh($abandoned)->isFinished());
        self::assertSame(0.0, $this->fresh($abandoned)->getScoreA());
        self::assertFalse($this->fresh($running)->isFinished());
        self::assertSame(QuizTournamentChallengeStatus::Expired, $this->fresh($expired)->getStatus());
        self::assertSame(QuizTournamentChallengeStatus::Missed, $this->fresh($missed)->getStatus());
    }

    public function testTheResultTheDefeatAndTheCorrectionComeOnlyOnceTheDuelIsOver(): void
    {
        $duel = $this->duel(QuizTournamentDuelClock::LEAD_SECONDS + 1, 2);

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->url($duel).'/correction');
        self::assertResponseRedirects($this->url($duel));
        $this->client->request('GET', $this->url($duel).'/result');
        self::assertResponseRedirects($this->url($duel));

        foreach ([$this->chloe, $this->teacher, $this->admin] as $other) {
            $this->client->loginUser($other);
            foreach (['', '/state', '/result', '/correction'] as $suffix) {
                $this->client->request('GET', $this->url($duel).$suffix);
                self::assertResponseStatusCodeSame(404, $other->getUsername().' '.$suffix);
            }
        }

        $duel = $this->duel(600, 2, $this->bruno, $this->alice, 1100);
        $this->answerRow($duel, 0, $this->alice, [1], 1.0);
        $this->answerRow($duel, 1, $this->alice, [2], 0.0);
        $this->answerRow($duel, 0, $this->bruno, [2], 0.0);
        $this->line($this->alice)->recordDuel(1100, 1.0);
        $this->em()->flush();

        $this->client->loginUser($this->alice);
        $this->client->request('GET', $this->url($duel).'/result');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qt-verdict__title', 'VICTOIRE');
        self::assertSelectorExists('.qt-strip');

        $this->client->loginUser($this->bruno);
        $this->client->request('GET', $this->url($duel).'/result');
        self::assertSelectorTextContains('.qt-verdict__title', 'RATÉ');
        self::assertSelectorTextContains('.qt-consolation', 'Alice avait 100 points de plus que toi');
        self::assertSelectorTextContains('.qt-themes', 'Violation de données');
        self::assertSelectorTextContains('.qt-end-foot', 'Revanche · 3 restantes');

        $this->client->request('GET', $this->url($duel).'/correction?filter=errors&theme=Violation%20de%20donn%C3%A9es');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qt-filter__item.is-active', 'Mes erreurs · 2');
        self::assertSelectorTextContains('.qt-corr-where', 'Erreur 1 sur 2 · question 1');
        self::assertSelectorTextContains('.qt-answer--right', 'Alice l’avait');
        self::assertSelectorTextContains('.qt-corr-why', 'La raison de la question 0.');
        self::assertSelectorTextContains('.qt-corr-example', 'Un cas concret.');
        self::assertSelectorTextContains('.qt-chip--bg', 'Source : RGPD, art. 33');

        $this->client->request('GET', $this->page().'/duels');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.qt-history', 'Alice Test');
        self::assertSelectorTextContains('.qt-tab.is-active', 'Mes duels');
        self::assertSelectorExists('.qt-chart__line');
    }

    public function testMesDuelsIsAStudentsScreen(): void
    {
        $this->assertScreens($this->teacher, [$this->page().'/duels' => 404]);
        $this->assertScreens($this->alice, [$this->page().'/duels' => 200]);
    }

    /**
     * A duel between two players, created `$ago` seconds ago, with `$count` QCMs whose right
     * choice is 1 (the wrong one 2) and an explanation in the shape the question banks write.
     */
    private function duel(int $ago, int $count, ?User $b = null, ?User $a = null, int $ratingA = 1000): QuizTournamentDuel
    {
        $em = $this->em();
        $duel = new QuizTournamentDuel($this->fresh($this->tournament), $this->fresh($a ?? $this->alice), $this->fresh($b ?? $this->bruno), QuizTournamentDuelOrigin::Challenge, 42, new \DateTimeImmutable(\sprintf('-%d seconds', $ago)), $ratingA, 1000, false, false);
        $em->persist($duel);
        $source = $this->fresh($this->source);
        for ($rank = 0; $rank < $count; ++$rank) {
            $question = new QuizTournamentDuelQuestion($duel, $rank, QuestionType::Qcm, 20);
            $question->setLabel("Contexte\nQuestion ".$rank);
            $question->setExplanation("La raison de la question {$rank}.\n\nExemple : Un cas concret.\n\nSource : RGPD, art. 33");
            $question->setChoices([['id' => 1, 'label' => 'Juste', 'correct' => true], ['id' => 2, 'label' => 'Faux', 'correct' => false]]);
            $question->setSource($source, $this->sourceQuestion($source));
            $em->persist($question);
        }
        $em->flush();

        return $duel;
    }

    private function sourceQuestion(QuizTemplate $source): QuizQuestion
    {
        $question = new QuizQuestion($source);
        $question->setType(QuestionType::Qcm);
        $question->setLabel('?');
        $this->em()->persist($question);

        return $question;
    }

    /** @param list<int> $choices */
    private function answerRow(QuizTournamentDuel $duel, int $rank, User $player, array $choices, float $points): void
    {
        $question = $this->fresh($duel)->getQuestions()[$rank];
        \assert($question instanceof QuizTournamentDuelQuestion);
        $this->em()->persist(new QuizTournamentDuelAnswer($question, $this->fresh($player), $choices, null, null, $points, $duel->getStartedAt()->modify('+6 seconds')));
        $this->em()->flush();
    }

    private function line(User $user): QuizTournamentPlayer
    {
        $line = $this->em()->getRepository(QuizTournamentPlayer::class)->findOneBy(['tournament' => $this->tournament->getId(), 'user' => $user->getId()]);
        if (null === $line) {
            $line = new QuizTournamentPlayer($this->fresh($this->tournament), $this->fresh($user));
            $this->em()->persist($line);
            $this->em()->flush();
        }

        return $line;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function answer(User $player, QuizTournamentDuel $duel, array $body, int $expected = 200): array
    {
        $this->client->loginUser($player);
        $this->client->request('GET', $this->page());
        $this->client->request('POST', $this->url($duel).'/answer', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-CSRF-Token' => $this->csrfToken('quiz_tournament_duel'.$duel->getId()),
        ], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expected);

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function state(User $player, QuizTournamentDuel $duel): array
    {
        $this->client->loginUser($player);
        $this->client->request('GET', $this->url($duel).'/state');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, mixed> $state */
    private function answered(array $state, User $user): bool
    {
        return true === $this->at($state, 'answered', (string) $user->getId());
    }

    /** @param array<string, mixed> $state */
    private function resultOf(array $state, int $rank, User $user): ?float
    {
        $value = $this->at($state, 'results', $rank, (string) $user->getId());

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * A value deep in a decoded JSON answer, null when any step is missing.
     *
     * @param array<array-key, mixed> $data
     */
    private function at(array $data, string|int ...$path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function closeDuels(): string
    {
        $application = new Application($this->client->getKernel());
        $tester = new CommandTester($application->find('app:quiz-tournament:close-duels'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /**
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

    private function url(QuizTournamentDuel $duel): string
    {
        return $this->page().'/duels/'.$duel->getId();
    }
}
