<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * When a part of the cahier de texte becomes readable by students
 * (design_handoff_cahier_de_texte 2a, « Visibilité de la section » dropdown).
 *
 * The « is it visible now » computation lives in App\Entity\LessonLog::isSectionVisible():
 * AfterSession and Scheduled resolve into a date, one taken from the séance, the other entered.
 */
enum LessonLogVisibility: string
{
    case Now = 'now';
    case AfterSession = 'after_session';
    case Scheduled = 'scheduled';
    case Hidden = 'hidden';

    public function labelKey(): string
    {
        return match ($this) {
            self::Now => 'lessonLogVisibilityNowLabel',
            self::AfterSession => 'lessonLogVisibilityAfterSessionLabel',
            self::Scheduled => 'lessonLogVisibilityScheduledLabel',
            self::Hidden => 'lessonLogVisibilityHiddenLabel',
        };
    }

    /** The choice that asks for a date: the others need no input. */
    public function needsDate(): bool
    {
        return self::Scheduled === $this;
    }
}
