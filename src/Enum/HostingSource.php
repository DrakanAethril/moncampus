<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a stored hosting came from. The alternances the UFA manages are not here at all: they are
 * read from their contracts and never copied (design/validated/vivier-entreprises.md, R3).
 */
enum HostingSource: string
{
    /** « Ajouter un accueil », typed by an administrator. */
    case Manual = 'manual';

    /** A line of « Importer l'historique ». */
    case Import = 'import';

    /** « Terminer la recherche » with « Stage trouvé », on the class tracking screen. */
    case JobSearch = 'job_search';

    public function labelKey(): string
    {
        return match ($this) {
            self::Manual => 'hostingSourceManualLabel',
            self::Import => 'hostingSourceImportLabel',
            self::JobSearch => 'hostingSourceJobSearchLabel',
        };
    }
}
