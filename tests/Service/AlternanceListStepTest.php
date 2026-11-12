<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ReminderBatchStep;
use App\Service\AlternanceListStep;
use App\Service\ReminderBatch\ReminderBatchTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AlternanceListStepTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function knownKeys(): iterable
    {
        foreach (['engagement_tutor', 'engagement_student', 'engagement_center', 'tutor:1', 'student:2', 'supervisor:3', 'closed'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('knownKeys')]
    public function testAKnownKeyReadsBackAsItself(string $key): void
    {
        self::assertSame($key, AlternanceListStep::fromKey($key)?->key());
    }

    /** @return iterable<string, array{string}> */
    public static function unknownKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'a bilan step without its rank' => ['tutor'];
        yield 'rank zero' => ['tutor:0'];
        yield 'a rank that is no number' => ['student:x'];
        yield 'an engagement step with a rank' => ['engagement_tutor:1'];
        yield 'a terminated alternance is not a step of the filter' => ['inactive'];
        yield 'not opened is never where an alternance stands' => ['not_opened'];
    }

    #[DataProvider('unknownKeys')]
    public function testAnUnknownKeyIsNoFilter(string $key): void
    {
        self::assertNull(AlternanceListStep::fromKey($key));
    }

    public function testTheHomeBannerTargetIsTheSameStageOfTheLivret(): void
    {
        self::assertSame('engagement_tutor', AlternanceListStep::forTarget(new ReminderBatchTarget(ReminderBatchStep::EngagementTutor, null))?->key());
        self::assertSame('engagement_student', AlternanceListStep::forTarget(new ReminderBatchTarget(ReminderBatchStep::EngagementStudent, null))?->key());
        self::assertSame('tutor:2', AlternanceListStep::forTarget(new ReminderBatchTarget(ReminderBatchStep::PeriodTutor, 2))?->key());
        self::assertSame('student:1', AlternanceListStep::forTarget(new ReminderBatchTarget(ReminderBatchStep::PeriodStudent, 1))?->key());
    }

    public function testTheOptionsFollowTheOrderTheLivretIsFilledIn(): void
    {
        self::assertSame(
            ['engagement_tutor', 'engagement_student', 'engagement_center', 'tutor:1', 'student:1', 'supervisor:1', 'tutor:2', 'student:2', 'supervisor:2', 'closed'],
            array_map(static fn (AlternanceListStep $step): string => $step->key(), AlternanceListStep::options(2)),
        );
        self::assertCount(4, AlternanceListStep::options(0));
    }
}
