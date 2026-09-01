<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameEntry;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameFamily;
use App\Service\Game\GameObservationBoard;
use App\Service\Game\GameRuleCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The three readings an administrator settles a barème with
 * (App\Service\Game\GameObservationBoard).
 *
 * What is pinned here is what the screen is *for*: a rule that paid nothing must still appear, the
 * thresholds must be read against the whole schooling rather than the window, and the pace must be
 * a median brought to a month - a mean would be moved by one student, and a raw window total would
 * say a class earns a third of what it does when read on the tenth of the month.
 */
class GameObservationBoardTest extends FunctionalTestCase
{
    public function testARuleThatPaidNothingIsListedWithItsZero(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'obs.idle');
        $program = $this->playingProgram([$student]);

        $this->credit($student, $program, GameRuleCatalog::WORK_ON_TIME, GameFamily::Work, 30, $this->withinThisMonth());

        $rules = $this->board()->for($program, ...$this->thisMonth())->rules;
        $codes = array_map(static fn (array $row): string => $row['value']->code(), $rules);

        // The whole catalogue, not only what fired - a rule nobody triggers is the most common
        // mistake in a barème and leaves no trace at all in the ledger.
        self::assertContains(GameRuleCatalog::ENGAGEMENT_CERTIFICATION, $codes);
        self::assertSame(GameRuleCatalog::WORK_ON_TIME, $codes[0], 'the heaviest rule leads');

        $certification = $this->ruleRow($rules, GameRuleCatalog::ENGAGEMENT_CERTIFICATION);
        self::assertSame(0, $certification['lines']);
        self::assertSame(0, $certification['points']);
        self::assertSame(0.0, $certification['share']);
    }

    public function testLinesAndPointsAreBothReportedBecauseTheyAnswerDifferentQuestions(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'obs.lines');
        $program = $this->playingProgram([$student]);

        // 60 paid once against 5 paid four times: the same order of magnitude of points, and two
        // very different things to say about the barème.
        $this->credit($student, $program, GameRuleCatalog::ENGAGEMENT_CERTIFICATION, GameFamily::Engagement, 60, $this->withinThisMonth());
        for ($i = 1; $i <= 4; ++$i) {
            $this->credit($student, $program, GameRuleCatalog::ENGAGEMENT_WIKI, GameFamily::Engagement, 5, $this->withinThisMonth($i));
        }

        $rules = $this->board()->for($program, ...$this->thisMonth())->rules;

        self::assertSame(1, $this->ruleRow($rules, GameRuleCatalog::ENGAGEMENT_CERTIFICATION)['lines']);
        self::assertSame(60, $this->ruleRow($rules, GameRuleCatalog::ENGAGEMENT_CERTIFICATION)['points']);
        self::assertSame(4, $this->ruleRow($rules, GameRuleCatalog::ENGAGEMENT_WIKI)['lines']);
        self::assertSame(20, $this->ruleRow($rules, GameRuleCatalog::ENGAGEMENT_WIKI)['points']);
        self::assertEqualsWithDelta(0.75, $this->ruleRow($rules, GameRuleCatalog::ENGAGEMENT_CERTIFICATION)['share'], 1e-9);
    }

    public function testTheThresholdsAreReadAgainstTheWholeSchoolingAndNotAgainstTheWindow(): void
    {
        $ahead = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'obs.ahead');
        $behind = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'obs.behind');
        $program = $this->playingProgram([$ahead, $behind]);

        // Earned last year: outside the window, and a level is never lost, so it still counts here.
        $this->credit($ahead, $program, GameRuleCatalog::ENGAGEMENT_CERTIFICATION, GameFamily::Engagement, 400, new \DateTimeImmutable('-8 months'));
        $this->credit($behind, $program, GameRuleCatalog::WORK_ON_TIME, GameFamily::Work, 30, $this->withinThisMonth());

        $levels = $this->board()->for($program, ...$this->thisMonth())->levels;

        self::assertSame(0, $levels[0]['level']->xpMin);
        self::assertSame(2, $levels[0]['reached'], 'everybody stands at level 1');
        self::assertSame(300, $levels[1]['level']->xpMin);
        self::assertSame(1, $levels[1]['reached'], 'only the one carrying last year\'s points');
        self::assertSame(0, $levels[3]['reached']);
    }

    public function testThePaceIsAMedianBroughtToAMonthRatherThanAWindowTotal(): void
    {
        $students = [];
        foreach (['a', 'b', 'c'] as $suffix) {
            $students[] = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'obs.pace.'.$suffix);
        }
        $program = $this->playingProgram($students);

        // 10 / 60 / 900: a mean would answer 323 and be moved by the last student alone.
        foreach ([10, 60, 900] as $index => $points) {
            $this->credit($students[$index], $program, GameRuleCatalog::WORK_ON_TIME, GameFamily::Work, $points, $this->withinThisMonth($index));
        }

        $now = new \DateTimeImmutable();
        $from = $now->modify('first day of this month')->setTime(0, 0);
        $observation = $this->board()->for($program, $from, $now->modify('last day of this month')->setTime(23, 59, 59), $now);

        // The median is 60, over the days the month has had so far, brought back to 30.
        self::assertSame((int) round(60 * 30 / $observation->daysSpent), $observation->pace);
        self::assertSame(970, $observation->windowPoints);
        self::assertSame(3, $observation->creditedCount());
    }

    public function testAClassThatHasEarnedNothingSaysSoRatherThanDividingByZero(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'obs.empty');
        $program = $this->playingProgram([$student]);

        $observation = $this->board()->for($program, ...$this->thisMonth());

        self::assertSame(0, $observation->pace);
        self::assertSame(0, $observation->creditedCount());
        // « jamais » is a reading; ∞ is not a number to print.
        self::assertNull($observation->levels[5]['months']);
        self::assertSame(0.0, $observation->rules[0]['share']);
    }

    /**
     * @param list<array{value: \App\Service\Game\GameRuleValue, lines: int, points: int, share: float}> $rules
     *
     * @return array{value: \App\Service\Game\GameRuleValue, lines: int, points: int, share: float}
     */
    private function ruleRow(array $rules, string $code): array
    {
        foreach ($rules as $row) {
            if ($row['value']->code() === $code) {
                return $row;
            }
        }

        self::fail(\sprintf('The rule %s is missing from the observation.', $code));
    }

    /** @param list<User> $students */
    private function playingProgram(array $students): Program
    {
        $program = $this->createProgram($students);
        $program->setGameEnabled(true);
        $this->entityManager()->flush();

        return $program;
    }

    private function credit(User $student, Program $program, string $code, GameFamily $family, int $points, \DateTimeImmutable $when): void
    {
        $entry = new GameEntry($student, $program, $family, $code, $points, $when);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();
    }

    /**
     * A moment inside the month being read, whatever day the suite is run on.
     *
     * Deliberately not « il y a trois jours »: the window is a **calendar month**, so on the first
     * of a month that reads as last month and the entry lands outside what the board is asked
     * about. Three tests failed exactly that way, on the 1st and only on the 1st. The rank spaces
     * the entries by an hour, which is all they need to be distinct.
     */
    private function withinThisMonth(int $rank = 0): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify('first day of this month')->setTime(0, 0)->modify('+'.$rank.' hours');
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function thisMonth(): array
    {
        $now = new \DateTimeImmutable();

        return [
            $now->modify('first day of this month')->setTime(0, 0),
            $now->modify('last day of this month')->setTime(23, 59, 59),
        ];
    }

    private function board(): GameObservationBoard
    {
        return static::getContainer()->get(GameObservationBoard::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
