<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AccessConditionComparison;
use App\Enum\AccessConditionMode;
use App\Enum\AccessConditionMoment;
use App\Enum\AccessConditionType;
use App\Service\AccessConditionEvaluator;
use App\Service\AccessConditionLeaf;
use App\Service\AccessConditionTree;
use App\Service\StudentAccessFacts;
use PHPUnit\Framework\TestCase;

/**
 * The whole rule of point 3, decided on primitives alone: identifiers, percentages and dates. No
 * entity and no database appear here, which is what makes the condition testable at this price -
 * loading the facts is a separate job (AccessConditionFactsLoader), and it is deliberately not
 * mixed into the decision.
 */
class AccessConditionEvaluatorTest extends TestCase
{
    private const NOW = '2026-09-18 16:30:00';

    /** No condition is not an unmet condition: an object without one is open to everybody. */
    public function testNoConditionIsSatisfied(): void
    {
        $verdict = $this->evaluate(null, $this->facts());

        self::assertTrue($verdict->satisfied);
        self::assertSame([], $verdict->unmet);
    }

    public function testQuizScoreReachesItsMinimum(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::QuizScore, 42, minPercent: 60)]);

        self::assertTrue($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 60]))->satisfied);
        self::assertTrue($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 91]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 59]))->satisfied);
    }

    /**
     * The remediation case, which is the reason min and max are two distinct keys rather than one
     * threshold and an operator: "moins de 60 %" is an orientation, not a failure.
     */
    public function testQuizScoreStaysUnderItsMaximum(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::QuizScore, 42, maxPercent: 60)]);

        self::assertTrue($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 35]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 80]))->satisfied);
    }

    /**
     * A quiz nobody has taken yet answers neither bound. Reading "no score" as "a score under 60"
     * would open every remediation to the whole class before anybody has composed - the exact
     * screen the conception refuses.
     */
    public function testAQuizNeverTakenSatisfiesNeitherBound(): void
    {
        $facts = $this->facts();

        self::assertFalse($this->evaluate($this->all([$this->leaf(AccessConditionType::QuizScore, 42, minPercent: 60)]), $facts)->satisfied);
        self::assertFalse($this->evaluate($this->all([$this->leaf(AccessConditionType::QuizScore, 42, maxPercent: 60)]), $facts)->satisfied);
    }

    public function testQuizScoreBetweenTwoBounds(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::QuizScore, 42, minPercent: 30, maxPercent: 60)]);

        self::assertTrue($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 45]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 20]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(quizBestPercents: [42 => 75]))->satisfied);
    }

    public function testGradeAboveItsThreshold(): void
    {
        $tree = $this->all([$this->gradeLeaf(88, AccessConditionComparison::Above, 10.0)]);

        self::assertTrue($this->evaluate($tree, $this->facts(gradeValues: [88 => 12.5]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(gradeValues: [88 => 8.0]))->satisfied);
    }

    /** Strictly: "supérieure à 10" is written by a teacher who does not want 10 to pass. */
    public function testAGradeExactlyOnItsThresholdDoesNotPass(): void
    {
        self::assertFalse($this->evaluate(
            $this->all([$this->gradeLeaf(88, AccessConditionComparison::Above, 10.0)]),
            $this->facts(gradeValues: [88 => 10.0]),
        )->satisfied);

        self::assertFalse($this->evaluate(
            $this->all([$this->gradeLeaf(88, AccessConditionComparison::Below, 15.0)]),
            $this->facts(gradeValues: [88 => 15.0]),
        )->satisfied);
    }

    /**
     * A grade condition written before the carnet de notes was switched off is **ignored**, not
     * enforced (design/validated/feature-access.md §8.4).
     *
     * The alternative is the one thing this must not do: "note ≥ 10" with nobody able to enter a
     * grade any more never becomes true, so the content behind it stays locked for ever and nothing
     * on screen explains why. A condition that cannot be satisfied must not forbid.
     */
    public function testAGradeConditionIsIgnoredWhenNobodyCanEnterAGradeAnyMore(): void
    {
        $tree = $this->all([$this->gradeLeaf(88, AccessConditionComparison::Above, 10.0)]);

        // The student has no grade at all, and would be locked out under the ordinary reading...
        self::assertFalse($this->evaluate($tree, $this->facts())->satisfied);
        // ...but is not, once nobody can give them one.
        self::assertTrue($this->evaluate($tree, $this->facts(gradesAreEnterable: false))->satisfied);
        // A grade that would have failed the bound does not fail it either: the leaf is out of the
        // decision entirely, not evaluated leniently.
        self::assertTrue($this->evaluate($tree, $this->facts(gradeValues: [88 => 3.0], gradesAreEnterable: false))->satisfied);
    }

    /** The other leaves keep deciding: only the grade ones step aside. */
    public function testTheOtherConditionsStillApplyWhenGradesAreOutOfService(): void
    {
        $tree = $this->all([
            $this->gradeLeaf(88, AccessConditionComparison::Above, 10.0),
            $this->leaf(AccessConditionType::QuizScore, 7, 50),
        ]);

        self::assertFalse($this->evaluate($tree, $this->facts(gradesAreEnterable: false))->satisfied);
        self::assertTrue($this->evaluate($tree, $this->facts(quizBestPercents: [7 => 80], gradesAreEnterable: false))->satisfied);
    }

    /**
     * The case the feature was asked for: "< 15 et > 10 à Sommative HTML" is two leaves on the same
     * evaluation, combined by "toutes les conditions" - no range type of its own.
     */
    public function testTwoGradeLeavesOnTheSameEvaluationMakeARange(): void
    {
        $tree = $this->all([
            $this->gradeLeaf(88, AccessConditionComparison::Above, 10.0),
            $this->gradeLeaf(88, AccessConditionComparison::Below, 15.0),
        ]);

        self::assertTrue($this->evaluate($tree, $this->facts(gradeValues: [88 => 12.0]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(gradeValues: [88 => 9.0]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(gradeValues: [88 => 17.0]))->satisfied);
    }

    /**
     * No grade is not a low grade. A student who was absent, was never evaluated or whose grade is
     * excluded has no note at all, so "moins de 10" must not open for them - the same rule as a
     * quiz nobody has taken.
     */
    public function testAnUngradedStudentSatisfiesNeitherComparison(): void
    {
        $facts = $this->facts();

        self::assertFalse($this->evaluate($this->all([$this->gradeLeaf(88, AccessConditionComparison::Below, 10.0)]), $facts)->satisfied);
        self::assertFalse($this->evaluate($this->all([$this->gradeLeaf(88, AccessConditionComparison::Above, 10.0)]), $facts)->satisfied);
    }

    public function testAssignmentDone(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::AssignmentDone, 17)]);

        self::assertTrue($this->evaluate($tree, $this->facts(doneAssignmentIds: [17]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(doneAssignmentIds: [18]))->satisfied);
    }

    /** A listening with no percentage asked for means the whole thing, as the audio tool defines it. */
    public function testAudioListenedDefaultsToTheWholeRecording(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::AudioListened, 9)]);

        self::assertTrue($this->evaluate($tree, $this->facts(audioPercents: [9 => 100]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(audioPercents: [9 => 99]))->satisfied);
    }

    public function testVideoWatchedHonoursItsMinimum(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::VideoWatched, 4, minPercent: 80)]);

        self::assertTrue($this->evaluate($tree, $this->facts(videoPercents: [4 => 80]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(videoPercents: [4 => 12]))->satisfied);
    }

    public function testResourceViewed(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::ResourceViewed, 31)]);

        self::assertTrue($this->evaluate($tree, $this->facts(viewedResourceIds: [31]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(viewedResourceIds: [30]))->satisfied);
    }

    /**
     * The séance the timetable moved with it: the condition names the séance, and reads whatever
     * slot it currently sits in - exactly as the cahier de texte resolves "après la séance".
     */
    public function testSeancePassedReadsTheSlotItCurrentlySitsIn(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::SeancePassed, 412)]);

        self::assertTrue($this->evaluate($tree, $this->facts(seanceEndDates: [412 => '2026-09-18 16:00:00']))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(seanceEndDates: [412 => '2026-09-25 16:00:00']))->satisfied);
    }

    public function testSeancePassedOnItsStartMoment(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::SeancePassed, 412, moment: AccessConditionMoment::Start)]);
        $facts = $this->facts(seanceStartDates: [412 => '2026-09-18 14:00:00'], seanceEndDates: [412 => '2026-09-25 16:00:00']);

        self::assertTrue($this->evaluate($tree, $facts)->satisfied);
    }

    /**
     * A séance with no slot yet: the cahier de texte's own policy, no date so nothing opens. The
     * teacher is warned at save time rather than refused, which is a separate matter.
     */
    public function testASeanceWithNoSlotNeverOpens(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::SeancePassed, 412)]);

        self::assertFalse($this->evaluate($tree, $this->facts(seanceEndDates: [412 => null]))->satisfied);
    }

    public function testDateFrom(): void
    {
        self::assertTrue($this->evaluate($this->all([$this->dateLeaf('2026-09-15 08:00:00')]), $this->facts())->satisfied);
        self::assertFalse($this->evaluate($this->all([$this->dateLeaf('2026-10-15 08:00:00')]), $this->facts())->satisfied);
    }

    public function testGroupMembership(): void
    {
        $tree = $this->all([$this->leaf(AccessConditionType::Group, 5)]);

        self::assertTrue($this->evaluate($tree, $this->facts(groupIds: [5]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts(groupIds: [6]))->satisfied);
    }

    public function testEveryLeafMustHoldInAllMode(): void
    {
        $tree = $this->all([
            $this->leaf(AccessConditionType::AssignmentDone, 17),
            $this->leaf(AccessConditionType::ResourceViewed, 31),
        ]);

        self::assertFalse($this->evaluate($tree, $this->facts(doneAssignmentIds: [17]))->satisfied);
        self::assertTrue($this->evaluate($tree, $this->facts(doneAssignmentIds: [17], viewedResourceIds: [31]))->satisfied);
    }

    public function testOneLeafIsEnoughInAnyMode(): void
    {
        $tree = new AccessConditionTree(AccessConditionMode::Any, [
            $this->leaf(AccessConditionType::AssignmentDone, 17),
            $this->leaf(AccessConditionType::ResourceViewed, 31),
        ]);

        self::assertTrue($this->evaluate($tree, $this->facts(doneAssignmentIds: [17]))->satisfied);
        self::assertFalse($this->evaluate($tree, $this->facts())->satisfied);
    }

    /**
     * The verdict is not a boolean: what is missing is what the student reads on the locked row, so
     * only the leaves that actually fail come back.
     */
    public function testTheVerdictNamesTheLeavesThatFailed(): void
    {
        $tree = $this->all([
            $this->leaf(AccessConditionType::AssignmentDone, 17),
            $this->leaf(AccessConditionType::ResourceViewed, 31),
        ]);

        $unmet = $this->evaluate($tree, $this->facts(doneAssignmentIds: [17]))->unmet;

        self::assertCount(1, $unmet);
        self::assertSame(AccessConditionType::ResourceViewed, $unmet[0]->type);
    }

    /** In "au moins une" mode nothing single is missing: the whole alternative is. */
    public function testAnUnsatisfiedAnyReportsEveryBranch(): void
    {
        $tree = new AccessConditionTree(AccessConditionMode::Any, [
            $this->leaf(AccessConditionType::AssignmentDone, 17),
            $this->leaf(AccessConditionType::ResourceViewed, 31),
        ]);

        self::assertCount(2, $this->evaluate($tree, $this->facts())->unmet);
    }

    /**
     * evaluateMany() is the whole point of separating facts from the decision: N objects are read
     * against one set of facts, so a list of thirty works costs what one costs.
     */
    public function testManyTreesAreDecidedAgainstOneSetOfFacts(): void
    {
        $verdicts = (new AccessConditionEvaluator())->evaluateMany([
            'open' => $this->all([$this->leaf(AccessConditionType::AssignmentDone, 17)]),
            'locked' => $this->all([$this->leaf(AccessConditionType::AssignmentDone, 18)]),
            'unconditional' => null,
        ], $this->facts(doneAssignmentIds: [17]));

        self::assertTrue($verdicts['open']->satisfied);
        self::assertFalse($verdicts['locked']->satisfied);
        self::assertTrue($verdicts['unconditional']->satisfied);
    }

    /** @param list<AccessConditionLeaf> $leaves */
    private function all(array $leaves): AccessConditionTree
    {
        return new AccessConditionTree(AccessConditionMode::All, $leaves);
    }

    private function leaf(AccessConditionType $type, int $targetId, ?int $minPercent = null, ?int $maxPercent = null, AccessConditionMoment $moment = AccessConditionMoment::End): AccessConditionLeaf
    {
        return new AccessConditionLeaf($type, $targetId, $minPercent, $maxPercent, null, $moment);
    }

    private function gradeLeaf(int $evaluationId, AccessConditionComparison $comparison, float $value): AccessConditionLeaf
    {
        return new AccessConditionLeaf(
            AccessConditionType::GradeValue,
            $evaluationId,
            comparison: $comparison,
            value: $value,
        );
    }

    private function dateLeaf(string $at): AccessConditionLeaf
    {
        return new AccessConditionLeaf(AccessConditionType::DateFrom, null, null, null, new \DateTimeImmutable($at));
    }

    private function evaluate(?AccessConditionTree $tree, StudentAccessFacts $facts): \App\Service\AccessConditionVerdict
    {
        return (new AccessConditionEvaluator())->evaluate($tree, $facts);
    }

    /**
     * @param array<int, float|int>    $quizBestPercents
     * @param list<int>                $doneAssignmentIds
     * @param array<int, int>          $audioPercents
     * @param array<int, int>          $videoPercents
     * @param list<int>                $viewedResourceIds
     * @param array<int, string|null>  $seanceStartDates
     * @param array<int, string|null>  $seanceEndDates
     * @param list<int>                $groupIds
     * @param array<int, float>        $gradeValues
     */
    private function facts(
        array $quizBestPercents = [],
        array $doneAssignmentIds = [],
        array $audioPercents = [],
        array $videoPercents = [],
        array $viewedResourceIds = [],
        array $seanceStartDates = [],
        array $seanceEndDates = [],
        array $groupIds = [],
        array $gradeValues = [],
        bool $gradesAreEnterable = true,
    ): StudentAccessFacts {
        return new StudentAccessFacts(
            new \DateTimeImmutable(self::NOW),
            array_map(static fn (float|int $percent): float => (float) $percent, $quizBestPercents),
            array_fill_keys($doneAssignmentIds, true),
            $audioPercents,
            $videoPercents,
            array_fill_keys($viewedResourceIds, true),
            array_map(static fn (?string $at): ?\DateTimeImmutable => null === $at ? null : new \DateTimeImmutable($at), $seanceStartDates),
            array_map(static fn (?string $at): ?\DateTimeImmutable => null === $at ? null : new \DateTimeImmutable($at), $seanceEndDates),
            array_fill_keys($groupIds, true),
            $gradeValues,
            $gradesAreEnterable,
        );
    }
}
