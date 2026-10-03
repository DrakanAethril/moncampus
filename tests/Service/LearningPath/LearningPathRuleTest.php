<?php

declare(strict_types=1);

namespace App\Tests\Service\LearningPath;

use App\Service\LearningPath\LearningPathRule;
use PHPUnit\Framework\TestCase;

/**
 * The rule of a learning path (design/validated/cours-en-ligne.md, §10): a step is open when every
 * validation quiz before it is validated - and the consequences the user asked for, one test each.
 */
class LearningPathRuleTest extends TestCase
{
    public function testAPathWithoutQuizIsARunOfOpenCourses(): void
    {
        $states = self::states([self::course(), self::course(), self::course(opened: true)]);

        self::assertSame(['open', 'open', 'done'], $states);
    }

    public function testAQuizClosesEverythingAfterItUntilItIsValidated(): void
    {
        $steps = [self::course(opened: true), self::course(opened: true), self::quiz(70, best: 55), self::course(), self::course(), self::quiz(80)];

        self::assertSame(['done', 'done', 'open', 'locked', 'locked', 'locked'], self::states($steps));

        $verdicts = LearningPathRule::evaluate($steps);
        self::assertSame(2, $verdicts[3]['lockedBy']);
        self::assertSame(2, $verdicts[5]['lockedBy'], 'The first quiz not passed is the one that closes the rest.');
    }

    public function testReachingTheThresholdValidatesAndOpensTheNextOnes(): void
    {
        self::assertSame(['done', 'open', 'open'], self::states([self::quiz(70, best: 70), self::course(), self::course()]));
        self::assertSame(['open', 'locked'], self::states([self::quiz(70, best: 69), self::course()]));
    }

    public function testAStepAlreadyOpenedStaysOpenWhateverIsAddedBeforeIt(): void
    {
        // A quiz inserted before a course the person had already read, or a threshold raised.
        self::assertSame(['open', 'done', 'locked'], self::states([self::quiz(90, best: 60), self::course(opened: true), self::course()]));
    }

    public function testAnUnavailableStepIsSkippedAndClosesNothing(): void
    {
        $steps = [self::course(opened: true), self::quiz(70, available: false), self::course()];

        self::assertSame(['done', 'unavailable', 'open'], self::states($steps));
    }

    public function testCompletedMeansEverythingAvailableIsDone(): void
    {
        self::assertTrue(LearningPathRule::isCompleted(LearningPathRule::evaluate([self::course(opened: true), self::quiz(70, best: 90), self::course(available: false)])));
        self::assertFalse(LearningPathRule::isCompleted(LearningPathRule::evaluate([self::course(opened: true), self::quiz(70, best: 50)])));
        self::assertFalse(LearningPathRule::isCompleted(LearningPathRule::evaluate([self::course(available: false)])), 'Nothing to do is not finished.');
        self::assertFalse(LearningPathRule::isCompleted([]));
    }

    /**
     * @param list<array{available: bool, quiz: bool, threshold: int, best: ?int, opened: bool}> $steps
     *
     * @return list<string>
     */
    private static function states(array $steps): array
    {
        return array_map(static fn (array $verdict): string => $verdict['state']->value, LearningPathRule::evaluate($steps));
    }

    /** @return array{available: bool, quiz: bool, threshold: int, best: ?int, opened: bool} */
    private static function course(bool $opened = false, bool $available = true): array
    {
        return ['available' => $available, 'quiz' => false, 'threshold' => 0, 'best' => null, 'opened' => $opened];
    }

    /** @return array{available: bool, quiz: bool, threshold: int, best: ?int, opened: bool} */
    private static function quiz(int $threshold, ?int $best = null, bool $available = true): array
    {
        return ['available' => $available, 'quiz' => true, 'threshold' => $threshold, 'best' => $best, 'opened' => null !== $best];
    }
}
