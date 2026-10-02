<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\McpPrompt;

/**
 * « Tirer la fiche de synthèse d'un cours » (design/validated/cours-en-ligne.md, §12): the one-page
 * summary of an online course, added to it as its summary sheet.
 */
final class CourseSummarySheetPrompt implements McpPrompt
{
    public function name(): string
    {
        return 'fiche_de_synthese';
    }

    public function title(): string
    {
        return 'Tirer la fiche de synthèse d\'un cours';
    }

    public function description(): string
    {
        return 'Rédige la fiche de synthèse d\'un cours en ligne, d\'une page, et l\'ajoute à ses supports.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'cours', 'description' => 'Le cours en ligne (son titre).', 'required' => true],
        ];
    }

    public function render(array $arguments): string
    {
        return implode("\n", [
            \sprintf('Rédige la fiche de synthèse de mon cours en ligne « %s ».', $arguments['cours'] ?? ''),
            '1. Trouve le cours avec course_list et lis-le avec course_get ; lis aussi ses supports (le PDF ou le support de départ, avec file_read s\'il est dans ma bibliothèque).',
            '2. Écris une fiche d\'une page en Markdown : les notions essentielles, les définitions, un exemple, les pièges à éviter. Pas de phrase d\'introduction inutile.',
            '3. Ajoute-la au cours avec course_material_add, kind « summary », source « markdown ». S\'il a déjà une fiche, demande-moi s\'il faut la remplacer (course_material_replace).',
            '4. Donne-moi le lien de la fiche du cours et dis-moi si le cours est en ligne.',
        ]);
    }
}
