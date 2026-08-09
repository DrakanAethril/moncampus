<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le format attendu pour une production d'un travail de type Dépôt (design_handoff_creation_travail
 * 2a, étape « Consigne »). Volontairement des familles de fichiers et non des extensions : c'est ce
 * que l'enseignant annonce à l'étudiant, pas ce que le stockage vérifie.
 *
 * Distinct d'Assignment::$acceptedFormats, qui reste la liste globale des formats acceptés au dépôt
 * pour les travaux créés sans productions détaillées (écran devoir historique et cahier de texte).
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
