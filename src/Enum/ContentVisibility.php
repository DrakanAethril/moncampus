<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * When a sequence or a séance of a program becomes readable by its students.
 *
 * Deliberately narrower than App\Enum\LessonLogVisibility, which also carries AfterSession: the
 * cahier de texte hangs off a LessonSession and can resolve "after the class", a sequence does not.
 * The vocabulary is otherwise the same on purpose - one meaning of "published" across the app.
 *
 * Hidden is the default everywhere it is stored: nothing of a sequence reaches a student until a
 * teacher says so, the same reflex the lesson log applies to its own sections.
 */
enum ContentVisibility: string
{
    case Hidden = 'hidden';
    case Published = 'published';
    case Scheduled = 'scheduled';

    public function labelKey(): string
    {
        return match ($this) {
            self::Hidden => 'contentVisibilityHiddenLabel',
            self::Published => 'contentVisibilityPublishedLabel',
            self::Scheduled => 'contentVisibilityScheduledLabel',
        };
    }

    /** The one choice that asks for a date; the others need no input. */
    public function needsDate(): bool
    {
        return self::Scheduled === $this;
    }

    /**
     * A Scheduled entry with no date is *not* visible - the same policy the cahier de texte applies
     * to a séance with no créneau. Treating a missing date as "always" would publish content by
     * accident, which is the one mistake this whole feature must not make.
     *
     * The bound is inclusive: content published "at 10:00" is readable on the stroke of ten.
     */
    public function isVisibleAt(?\DateTimeImmutable $publishedAt, \DateTimeImmutable $now): bool
    {
        return match ($this) {
            self::Hidden => false,
            self::Published => true,
            self::Scheduled => null !== $publishedAt && $publishedAt <= $now,
        };
    }
}
