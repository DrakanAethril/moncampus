<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ContentVisibility;
use PHPUnit\Framework\TestCase;

/**
 * Whether a sequence or a séance of the course space is readable by a student.
 *
 * Expressed on a nullable date and a clock rather than on an entity: the rule is a comparison, and
 * a comparison is worth testing without a database behind it - same stance as QuizInstanceStateTest.
 */
class ContentVisibilityTest extends TestCase
{
    private const string NOW = '2026-09-15 10:00:00';

    public function testHiddenIsNeverVisible(): void
    {
        self::assertFalse($this->visible(ContentVisibility::Hidden, null));
        self::assertFalse($this->visible(ContentVisibility::Hidden, '2020-01-01 00:00:00'));
    }

    public function testPublishedIsAlwaysVisible(): void
    {
        self::assertTrue($this->visible(ContentVisibility::Published, null));
        self::assertTrue($this->visible(ContentVisibility::Published, '2099-01-01 00:00:00'));
    }

    /**
     * The same policy the cahier de texte already applies to a séance with no créneau: no date
     * means not yet, never "always". Anything else would publish content by accident.
     */
    public function testScheduledWithoutADateIsNotVisible(): void
    {
        self::assertFalse($this->visible(ContentVisibility::Scheduled, null));
    }

    public function testScheduledBeforeItsDateIsNotVisible(): void
    {
        self::assertFalse($this->visible(ContentVisibility::Scheduled, '2026-09-15 10:00:01'));
        self::assertFalse($this->visible(ContentVisibility::Scheduled, '2026-10-01 08:00:00'));
    }

    /** The bound is inclusive: content published "at 10:00" is readable on the stroke of ten. */
    public function testScheduledOnItsExactDateIsVisible(): void
    {
        self::assertTrue($this->visible(ContentVisibility::Scheduled, self::NOW));
    }

    public function testScheduledAfterItsDateIsVisible(): void
    {
        self::assertTrue($this->visible(ContentVisibility::Scheduled, '2026-09-15 09:59:59'));
        self::assertTrue($this->visible(ContentVisibility::Scheduled, '2026-01-01 08:00:00'));
    }

    public function testOnlyScheduledAsksForADate(): void
    {
        self::assertTrue(ContentVisibility::Scheduled->needsDate());
        self::assertFalse(ContentVisibility::Published->needsDate());
        self::assertFalse(ContentVisibility::Hidden->needsDate());
    }

    /**
     * A shared label key would silently show two different states under the same wording. A case
     * added with no label of its own needs no assertion: labelKey()'s match is exhaustive, so it
     * throws before reaching here.
     */
    public function testEveryCaseHasItsOwnLabelKey(): void
    {
        $keys = array_map(static fn (ContentVisibility $case): string => $case->labelKey(), ContentVisibility::cases());

        self::assertSame($keys, array_unique($keys));
    }

    private function visible(ContentVisibility $visibility, ?string $publishedAt): bool
    {
        return $visibility->isVisibleAt(
            null === $publishedAt ? null : new \DateTimeImmutable($publishedAt),
            new \DateTimeImmutable(self::NOW),
        );
    }
}
