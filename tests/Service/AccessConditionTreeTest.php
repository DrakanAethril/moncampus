<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AccessConditionMode;
use App\Enum\AccessConditionMoment;
use App\Enum\AccessConditionType;
use App\Service\AccessConditionLeaf;
use App\Service\AccessConditionTree;
use PHPUnit\Framework\TestCase;

/**
 * The boundary between the access_condition column and the rest of the application. A stored
 * condition is read once, here, and everything downstream works on typed leaves - the reflex this
 * repository already applies to blanks_config, zoneConfig and matchingConfig.
 */
class AccessConditionTreeTest extends TestCase
{
    public function testItReadsTheShapeTheConceptionWrites(): void
    {
        $tree = AccessConditionTree::fromArray(['all' => [
            ['type' => 'quiz_score', 'instance' => 42, 'min_percent' => 60],
            ['type' => 'assignment_done', 'assignment' => 17],
            ['type' => 'seance_passed', 'seance' => 412, 'moment' => 'end'],
            ['type' => 'date_from', 'at' => '2026-09-15T08:00:00+02:00'],
            ['type' => 'group', 'group' => 5],
        ]]);

        self::assertNotNull($tree);
        self::assertSame(AccessConditionMode::All, $tree->mode);
        self::assertCount(5, $tree->leaves);
        self::assertSame(42, $tree->leaves[0]->targetId);
        self::assertSame(60, $tree->leaves[0]->minPercent);
        self::assertSame('2026-09-15', $tree->leaves[3]->at?->format('Y-m-d'));
    }

    public function testItReadsTheAnyShape(): void
    {
        $tree = AccessConditionTree::fromArray(['any' => [['type' => 'assignment_done', 'assignment' => 17]]]);

        self::assertSame(AccessConditionMode::Any, $tree?->mode);
    }

    /** A condition survives a round trip through the column unchanged, or it is not storable. */
    public function testItSurvivesARoundTrip(): void
    {
        $stored = ['all' => [
            ['type' => 'quiz_score', 'instance' => 42, 'min_percent' => 30, 'max_percent' => 60],
            ['type' => 'seance_passed', 'seance' => 412, 'moment' => 'start'],
        ]];

        self::assertSame($stored, AccessConditionTree::fromArray($stored)?->toArray());
    }

    /**
     * An emptied form must read as "no condition", not as "a condition nobody can meet" - saving one
     * would otherwise lock an object silently.
     */
    public function testAnEmptyConditionIsNoCondition(): void
    {
        self::assertNull(AccessConditionTree::fromArray(null));
        self::assertNull(AccessConditionTree::fromArray([]));
        self::assertNull(AccessConditionTree::fromArray(['all' => []]));
    }

    /**
     * A leaf that cannot describe anything is dropped rather than thrown on: a row left by an older
     * format must not take a student's screen down with it.
     */
    public function testUnreadableLeavesAreDroppedNotFatal(): void
    {
        $tree = AccessConditionTree::fromArray(['all' => [
            ['type' => 'no_such_type', 'instance' => 1],
            ['type' => 'assignment_done'],
            ['type' => 'date_from', 'at' => 'not a date'],
            ['type' => 'assignment_done', 'assignment' => 17],
        ]]);

        self::assertCount(1, $tree?->leaves ?? []);
    }

    public function testAMaximumOnlyMeansSomethingOnAScore(): void
    {
        $tree = AccessConditionTree::fromArray(['all' => [
            ['type' => 'audio_listened', 'recording' => 9, 'min_percent' => 100, 'max_percent' => 50],
        ]]);

        self::assertNull($tree?->leaves[0]->maxPercent);
    }

    public function testPercentagesAreClampedAtTheBoundary(): void
    {
        $tree = AccessConditionTree::fromArray(['all' => [
            ['type' => 'quiz_score', 'instance' => 42, 'min_percent' => 260],
        ]]);

        self::assertSame(100, $tree?->leaves[0]->minPercent);
    }

    /**
     * What the teacher's form posts: the stored shape, except that the picked object always travels
     * under one key. The screen has one select for it, so it would have to learn eight key names to
     * write "instance" for a quiz and "recording" for a listening - and would eventually write one
     * of them wrong.
     */
    public function testItReadsWhatTheFormPosts(): void
    {
        $tree = AccessConditionTree::fromSubmitted('all', [
            ['type' => 'quiz_score', 'target' => '42', 'min_percent' => '60'],
            ['type' => 'seance_passed', 'target' => '412', 'moment' => 'start'],
            ['type' => 'date_from', 'at' => '2026-09-15T08:00'],
        ]);

        self::assertSame(['all' => [
            ['type' => 'quiz_score', 'instance' => 42, 'min_percent' => 60],
            ['type' => 'seance_passed', 'seance' => 412, 'moment' => 'start'],
            ['type' => 'date_from', 'at' => '2026-09-15T08:00:00+02:00'],
        ]], $tree?->toArray());
    }

    public function testAnUnknownModeFallsBackOnAll(): void
    {
        $tree = AccessConditionTree::fromSubmitted('sometimes', [['type' => 'assignment_done', 'target' => 17]]);

        self::assertSame(AccessConditionMode::All, $tree?->mode);
    }

    /** What the facts loader groups its queries on: one per type, never one per leaf. */
    public function testItGroupsItsTargetsByType(): void
    {
        $tree = new AccessConditionTree(AccessConditionMode::All, [
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 17),
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 18),
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 17),
            new AccessConditionLeaf(AccessConditionType::SeancePassed, 412, moment: AccessConditionMoment::Start),
            new AccessConditionLeaf(AccessConditionType::DateFrom, at: new \DateTimeImmutable()),
        ]);

        self::assertSame(['assignment_done' => [17, 18], 'seance_passed' => [412]], $tree->targetIdsByType());
    }
}
