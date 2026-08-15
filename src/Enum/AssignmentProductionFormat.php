<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The format expected for a production of a Dépôt assignment (design_handoff_creation_travail 2a,
 * « Consigne » step). Deliberately file families and not extensions: this is what the teacher
 * announces to the student, not what the storage checks.
 *
 * Distinct from Assignment::$acceptedFormats, which remains the global list of formats accepted on
 * submission for the assignments created without detailed productions (the historical assignment
 * screen and the cahier de texte).
 */
enum AssignmentProductionFormat: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    case Spreadsheet = 'spreadsheet';
    case Url = 'url';
    case Archive = 'archive';
    case Any = 'any';

    public function labelKey(): string
    {
        return match ($this) {
            self::Image => 'assignmentProductionFormatImageLabel',
            self::Pdf => 'assignmentProductionFormatPdfLabel',
            self::Spreadsheet => 'assignmentProductionFormatSpreadsheetLabel',
            self::Url => 'assignmentProductionFormatUrlLabel',
            self::Archive => 'assignmentProductionFormatArchiveLabel',
            self::Any => 'assignmentProductionFormatAnyLabel',
        };
    }
}
