<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a dossier intervenant is in its own life.
 *
 * A **draft** is invisible to the intervenant: the administration is still composing it. A
 * **published** one is what the intervenant reads and deposits on. A **closed** one stays readable
 * on both sides, accepts nothing more and no longer lights the banner of the home page - which is
 * the whole reason the third state exists: without it the unfinished dossier of somebody who left
 * would read « incomplet » for ever, and its owner would be asked for pieces every time they sign in.
 *
 * Unlike a dossier documentaire's publication, closing is not one-way: a closed dossier is reopened
 * by the same people who closed it.
 */
enum TeacherDossierState: string implements HasBadge
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';

    #[\Override]
    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'teacherDossierStateDraftLabel',
            self::Published => 'teacherDossierStatePublishedLabel',
            self::Closed => 'teacherDossierStateClosedLabel',
        };
    }

    #[\Override]
    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Draft => BadgeTone::Gray,
            self::Published => BadgeTone::Green,
            self::Closed => BadgeTone::Blue,
        };
    }
}
