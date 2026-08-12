<?php

declare(strict_types=1);

namespace App\Tests\Service;

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

    private function dateLeaf(string $at): AccessConditionLeaf
    {
        return new AccessConditionLeaf(AccessConditionType::DateFrom, null, null, null, new \DateTimeImmutable($at));
    }

    private function evaluate(?AccessConditionTree $tree, StudentAccessFacts $facts): \App\Service\AccessConditionVerdict
    {
        return (new AccessConditionEvaluator())->evaluate($tree, $facts);
    }

    /**
     * @param array<int, int>          $quizBestPercents
     * @param list<int>                $doneAssignmentIds
     * @param array<int, int>          $audioPercents
     * @param array<int, int>          $videoPercents
     * @param list<int>                $viewedResourceIds
     * @param array<int, string|null>  $seanceStartDates
     * @param array<int, string|null>  $seanceEndDates
     * @param list<int>                $groupIds
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
    ): StudentAccessFacts {
        return new StudentAccessFacts(
            new \DateTimeImmutable(self::NOW),
            $quizBestPercents,
            array_fill_keys($doneAssignmentIds, true),
            $audioPercents,
            $videoPercents,
            array_fill_keys($viewedResourceIds, true),
            array_map(static fn (?string $at): ?\DateTimeImmutable => null === $at ? null : new \DateTimeImmutable($at), $seanceStartDates),
            array_map(static fn (?string $at): ?\DateTimeImmutable => null === $at ? null : new \DateTimeImmutable($at), $seanceEndDates),
            array_fill_keys($groupIds, true),
        );
    }
}
