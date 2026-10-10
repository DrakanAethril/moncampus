<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The filters of the administration's list of dossiers intervenants, in the order the bar draws
 * them. The list opens on `Incomplete` - what somebody comes to this screen to look for.
 */
enum TeacherDossierFilter: string
{
    case Incomplete = 'incomplete';
    case ToReview = 'review';
    case Complete = 'complete';
    case Draft = 'draft';
    case Closed = 'closed';
    case All = 'all';

    public function labelKey(): string
    {
        return match ($this) {
            self::Incomplete => 'teacherDossierFilterIncompleteLabel',
            self::ToReview => 'teacherDossierFilterToReviewLabel',
            self::Complete => 'teacherDossierFilterCompleteLabel',
            self::Draft => 'teacherDossierFilterDraftLabel',
            self::Closed => 'teacherDossierFilterClosedLabel',
            self::All => 'teacherDossierFilterAllLabel',
        };
    }
}
