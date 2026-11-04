<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelQuestion;
use App\Entity\Section;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentChallengeRefusal;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Repository\QuizTournamentChallengeRepository;
use App\Repository\QuizTournamentDuelRepository;
use App\Service\QuizTournament\QuizTournamentAccess;
use App\Service\QuizTournament\QuizTournamentChallengeRules;
use App\Service\StructureTargeting\StructureTargeting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The facts the tournament's pure rules decide on, read from a real database: the targeting's
 * queries (StructureTargeting), the challenge facts (QuizTournamentAccess) and the duel facts of the
 * standings and the draw (QuizTournamentDuelRepository). The rules themselves are pinned on
 * primitives in tests/Service/QuizTournament/ and tests/Service/StructureTargeting/.
 */
class QuizTournamentFactsTest extends FunctionalTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheTargetingReachesTheStudentsOfTheTargetedStructureOnly(): void
    {
        $alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'alice');
        $bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'bruno');
        $chloe = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'chloe');
        $author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'fixture.author');
        $targeted = $this->createProgram([$alice, $bruno], [], $author);
        $other = $this->createProgram([$chloe], [], $author);
        $tournament = $this->tournament();
        $tournament->addTargetSection($this->sectionOf($targeted));
        $this->em->flush();

        $targeting = static::getContainer()->get(StructureTargeting::class);

        self::assertTrue($targeting->reaches($tournament, $alice));
        self::assertFalse($targeting->reaches($tournament, $chloe));
        self::assertSame([$targeted->getId()], $targeting->reachedProgramIds($tournament));
        self::assertSame([$targeted], $targeting->reachedPrograms($tournament, $alice));

        $reached = $targeting->reachedStudents($tournament);
        self::assertEqualsCanonicalizing([$alice->getId(), $bruno->getId()], array_keys($reached));
        self::assertSame($targeted, $reached[(int) $alice->getId()]->program);

        // An inactive formation reaches nobody any more.
        $targeted->setInactiveDate(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        self::assertFalse($targeting->reaches($tournament, $alice));
        self::assertSame([], $targeting->reachedStudents($tournament));
        self::assertNotNull($other->getId());
    }

    public function testATestAccountIsReachedThroughATestProgramOnly(): void
    {
        $tester = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'tester');
        $tester->setTestUser(true);
        $program = $this->createProgram([$tester]);
        $tournament = $this->tournament();
        $tournament->addTargetProgram($program);
        $this->em->flush();

        $targeting = static::getContainer()->get(StructureTargeting::class);
        self::assertFalse($targeting->reaches($tournament, $tester));
        self::assertSame([], $targeting->reachedStudents($tournament));

        $program->setTestProgram(true);
        $this->em->flush();
        self::assertTrue($targeting->reaches($tournament, $tester));
    }

    public function testTheChallengeFactsCountWhatIsPendingRefusedAndMetToday(): void
    {
        $alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'alice');
        $bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'bruno');
        $program = $this->createProgram([$alice, $bruno]);
        $tournament = $this->tournament();
        $tournament->addTargetProgram($program);
        $now = new \DateTimeImmutable('today 15:00');

        // A refused challenge this morning, a pending one an hour ago, and an old expired one.
        $refused = new QuizTournamentChallenge($tournament, $alice, $bruno, false, $now->modify('-6 hours'));
        $refused->refuse($now->modify('-5 hours'));
        $pending = new QuizTournamentChallenge($tournament, $bruno, $alice, false, $now->modify('-1 hour'));
        $expired = new QuizTournamentChallenge($tournament, $alice, $bruno, true, $now->modify('-3 days'));
        foreach ([$refused, $pending, $expired] as $challenge) {
            $this->em->persist($challenge);
        }
        $this->em->flush();

        $facts = static::getContainer()->get(QuizTournamentAccess::class)->challengeFacts($tournament, $alice, $bruno, $now);

        self::assertTrue($facts->inProgress);
        self::assertTrue($facts->challengerEligible);
        self::assertTrue($facts->challengedEligible);
        self::assertSame(0, $facts->challengerPendingOutgoing);
        self::assertSame(0, $facts->challengedPendingIncoming, 'the pending one goes the other way');
        self::assertEquals($now->modify('-5 hours'), $facts->lastRefusalByChallengedAt);
        self::assertCount(2, $facts->pairEncounters, 'today, both directions, whatever the outcome');
        self::assertSame(
            [QuizTournamentChallengeRefusal::RecentlyRefused],
            QuizTournamentChallengeRules::refusals($facts, $now),
        );

        $reverse = static::getContainer()->get(QuizTournamentAccess::class)->challengeFacts($tournament, $bruno, $alice, $now);
        self::assertSame(1, $reverse->challengerPendingOutgoing);
        self::assertSame(
            [QuizTournamentChallengeRefusal::OutgoingPending],
            QuizTournamentChallengeRules::refusals($reverse, $now),
        );

        self::assertSame(
            [(int) $alice->getId()],
            static::getContainer()->get(QuizTournamentChallengeRepository::class)->ladderDeclinedChallengerIds($tournament, $now->modify('-14 days'), $now),
            'the expired ladder challenge holds the ladder rule for its challenger',
        );

        // The standings read the same facts for every line at once: they must be the very same.
        $access = static::getContainer()->get(QuizTournamentAccess::class);
        foreach ([[$alice, $bruno], [$bruno, $alice]] as [$challenger, $challenged]) {
            $byOpponent = $access->challengeFactsByOpponent($tournament, $challenger, [(int) $challenged->getId() => true], $now);
            self::assertEquals($access->challengeFacts($tournament, $challenger, $challenged, $now), $byOpponent[(int) $challenged->getId()]);
        }
    }

    public function testTheDuelFactsOfTheStandingsAndTheDraw(): void
    {
        $alice = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'alice');
        $bruno = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'bruno');
        $tournament = $this->tournament();
        $quiz = new QuizTemplate($alice);
        $quiz->setName('Repérer une donnée personnelle');
        $quiz->setCreatedBy($alice);
        $this->em->persist($quiz);
        $questions = [];
        for ($i = 0; $i < 5; ++$i) {
            $questions[] = $question = new QuizQuestion($quiz);
            $question->setLabel('Question '.$i);
            $this->em->persist($question);
        }

        $now = new \DateTimeImmutable('today 15:00');
        // Four duels: the oldest one's questions are no longer « recently seen ».
        foreach ([[-4, [0]], [-3, [1]], [-2, [2]], [-1, [3, 4]]] as [$days, $indexes]) {
            $duel = new QuizTournamentDuel($tournament, $alice, $bruno, QuizTournamentDuelOrigin::Round, 1, $now->modify($days.' days'), 1000, 1000, true, false);
            foreach ($indexes as $rank => $index) {
                (new QuizTournamentDuelQuestion($duel, $rank, QuestionType::Qcm, 20))
                    ->setLabel('Question '.$index)
                    ->setSource($quiz, $questions[$index]);
            }
            $duel->finish($now->modify($days.' days +10 minutes'), 10, 8, 1010, 990);
            $this->em->persist($duel);
        }
        $unfinished = new QuizTournamentDuel($tournament, $bruno, $alice, QuizTournamentDuelOrigin::Challenge, 2, $now, 1000, 1000, false, false);
        $this->em->persist($unfinished);
        $this->em->flush();

        $duels = static::getContainer()->get(QuizTournamentDuelRepository::class);

        self::assertTrue($duels->existsForTournament($tournament));
        self::assertSame([(int) $alice->getId() => 4, (int) $bruno->getId() => 4], $duels->finishedDuelCounts($tournament, $now->modify('-14 days'), $now->modify('+1 minute')));
        self::assertSame([(int) $alice->getId()], $duels->ladderPlayerIds($tournament, $now->modify('-14 days'), $now->modify('+1 minute')));
        // The last three duels are the unfinished one (no question yet) and the two before.
        self::assertEqualsCanonicalizing(
            [$questions[2]->getId(), $questions[3]->getId(), $questions[4]->getId()],
            $duels->recentlySeenQuestionIds($tournament, $alice),
        );
        self::assertSame($unfinished, $duels->findUnfinishedFor($alice));
        self::assertCount(1, $duels->roundEncounterTimes($tournament, $bruno, $alice, $now->modify('-1 day midnight'), $now->modify('midnight')));
    }

    private function tournament(): QuizTournament
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'tournament.admin');
        $tournament = new QuizTournament('Tournoi RGPD', new \DateTimeImmutable('-1 month'), new \DateTimeImmutable('+2 months'), QuizTournamentPalette::Lavender, $admin);
        $tournament->open($admin, new \DateTimeImmutable('-1 month'));
        $this->em->persist($tournament);
        $this->em->flush();

        return $tournament;
    }

    private function sectionOf(Program $program): Section
    {
        $section = $program->getCohort()?->getTrack()?->getSection();
        self::assertNotNull($section);

        return $section;
    }
}
