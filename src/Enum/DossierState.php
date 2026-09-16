<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a dossier is in its own life: still being composed, or handed to its cibles.
 *
 * The line between the two is the only irreversible thing this feature does. A draft is invisible
 * to everybody but its validateurs, and a published dossier is what the cibles read - so
 * publication is what makes the documents' own dates start meaning something, and there is no
 * "unpublish": a cible who has already deposited a file cannot be told the dossier never existed.
 */
enum DossierState: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function labelKey(): string
    {
        return match ($this) {
            self::Draft => 'dossierStateDraftLabel',
            self::Published => 'dossierStatePublishedLabel',
        };
    }

    /** The `cm-dd-tag--*` modifier the pill carries - green for published, neutral for a draft. */
    public function tagModifier(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Published => 'valide',
        };
    }
}
