<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Quand un temps du cahier de texte devient lisible par les étudiants
 * (design_handoff_cahier_de_texte 2a, dropdown « Visibilité de la section »).
 *
 * Le calcul du « est-ce visible maintenant » vit dans App\Entity\LessonLog::isSectionVisible() :
 * AfterSession et Scheduled se résolvent en une date, l'une prise sur la séance, l'autre saisie.
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

    /** Le choix qui demande une date : les autres se passent de saisie. */
    public function needsDate(): bool
    {
        return self::Scheduled === $this;
    }
}
