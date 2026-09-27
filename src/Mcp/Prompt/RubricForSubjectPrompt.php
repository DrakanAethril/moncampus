<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\McpPrompt;

/**
 * « Construire le barème d'un sujet » - from a subject the teacher joins or keeps in the library,
 * to an evaluation of the carnet de notes carrying its barème, still hidden from the class.
 */
final class RubricForSubjectPrompt implements McpPrompt
{
    public function name(): string
    {
        return 'bareme_d_un_sujet';
    }

    public function title(): string
    {
        return 'Construire le barème d\'un sujet';
    }

    public function description(): string
    {
        return 'Lit un sujet d\'évaluation, en propose le barème (parties, questions, points) et le pose sur une évaluation du carnet de notes.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'sujet', 'description' => 'Le sujet : nom d\'un fichier de la bibliothèque, ou « le document joint ».', 'required' => false],
            ['name' => 'classe', 'description' => 'La classe et la matière de l\'évaluation.', 'required' => false],
            ['name' => 'total', 'description' => 'Le total visé (20 par défaut).', 'required' => false],
        ];
    }

    public function render(array $arguments): string
    {
        $total = is_numeric($arguments['total'] ?? '') ? $arguments['total'] : '20';

        return implode("\n", array_filter([
            \sprintf('Construis le barème sur %s de mon sujet d\'évaluation et pose-le dans mon carnet de notes MonCampus.', $total),
            '' !== ($arguments['sujet'] ?? '') ? \sprintf('Le sujet : %s (cherche-le avec file_list, lis-le avec file_read).', $arguments['sujet']) : 'Le sujet : le document que je joins à ce message.',
            '' !== ($arguments['classe'] ?? '') ? \sprintf('La classe et la matière : %s.', $arguments['classe']) : null,
            '1. Appelle format_guide avec format « bareme ».',
            '2. Propose-moi d\'abord le barème (parties, questions numérotées, points) et attends mon accord.',
            '3. Trouve la matière avec gradebook_overview. Si l\'évaluation existe déjà, pose le barème avec rubric_set ; sinon crée-la avec evaluation_create en lui donnant le barème.',
            '4. Rappelle-moi la date à partir de laquelle l\'évaluation sera visible des étudiants, et donne-moi le lien.',
        ]));
    }
}
