<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a piece of evidence comes from.
 *
 * The specification named « un fichier de ma bibliothèque »; students have no file library
 * (App\Security\Voter\FileLibraryVoter gives one to teachers and staff only), so a student's file is
 * **uploaded** through the platform picker and kept under the portfolio's own prefix.
 */
enum PortfolioEvidenceKind: string
{
    case File = 'file';
    case Link = 'link';
    case Submission = 'submission';

    public function labelKey(): string
    {
        return match ($this) {
            self::File => 'portfolioEvidenceFileLabel',
            self::Link => 'portfolioEvidenceLinkLabel',
            self::Submission => 'portfolioEvidenceSubmissionLabel',
        };
    }
}
