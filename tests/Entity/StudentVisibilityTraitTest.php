<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\StudentVisibilityTrait;
use App\Enum\ContentVisibility;
use PHPUnit\Framework\TestCase;

/**
 * The pairing rule of the course space's visibility fields.
 *
 * Exercised on a bare host class rather than on SequenceInstance: the rule belongs to the trait, and
 * building a Program and a User to reach it would make the test about entity constructors instead.
 */
class StudentVisibilityTraitTest extends TestCase
{
    public function testContentStartsHiddenAndUnpublished(): void
    {
        $content = $this->content();

        self::assertSame(ContentVisibility::Hidden, $content->getStudentVisibility());
        self::assertNull($content->getPublishedAt());
        self::assertFalse($content->isVisibleToStudentsAt());
    }

    public function testSchedulingKeepsItsDate(): void
    {
        $at = new \DateTimeImmutable('2026-09-18 08:00:00');
        $content = $this->content()->setStudentVisibility(ContentVisibility::Scheduled, $at);

        self::assertSame($at, $content->getPublishedAt());
    }

    /**
     * The reason the pair is set together: a date left behind by an earlier choice would come back
     * the day someone switches the entry to Scheduled again, publishing on a date nobody chose.
     */
    public function testLeavingScheduledDropsTheDate(): void
    {
        $content = $this->content()->setStudentVisibility(ContentVisibility::Scheduled, new \DateTimeImmutable('2026-09-18 08:00:00'));

        $content->setStudentVisibility(ContentVisibility::Published);
        self::assertNull($content->getPublishedAt());

        $content->setStudentVisibility(ContentVisibility::Scheduled);
        self::assertNull($content->getPublishedAt(), 'Scheduled with no date must not inherit the old one');
        self::assertFalse($content->isVisibleToStudentsAt());
    }

    public function testAPublishedEntryIsVisibleAndAHiddenOneIsNot(): void
    {
        self::assertTrue($this->content()->setStudentVisibility(ContentVisibility::Published)->isVisibleToStudentsAt());
        self::assertFalse($this->content()->setStudentVisibility(ContentVisibility::Hidden)->isVisibleToStudentsAt());
    }

    public function testAScheduledEntryFlipsOnItsDate(): void
    {
        $content = $this->content()->setStudentVisibility(ContentVisibility::Scheduled, new \DateTimeImmutable('2026-09-18 08:00:00'));

        self::assertFalse($content->isVisibleToStudentsAt(new \DateTimeImmutable('2026-09-18 07:59:59')));
        self::assertTrue($content->isVisibleToStudentsAt(new \DateTimeImmutable('2026-09-18 08:00:00')));
    }

    private function content(): object
    {
        return new class {
            use StudentVisibilityTrait;
        };
    }
}
