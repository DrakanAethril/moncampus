<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\McpPrompt;

/**
 * « Préparer un quiz sur une séquence » - the quiz assistant's « à partir du cours » path: the
 * séquence or séance is read from the library, the quiz is attached back to it.
 */
final class QuizFromCoursePrompt implements McpPrompt
{
    public function name(): string
    {
        return 'quiz_sur_une_sequence';
    }

    public function title(): string
    {
        return 'Préparer un quiz sur une séquence';
    }

    public function description(): string
    {
        return 'Écrit un quiz sur une séquence ou une séance de la bibliothèque, le crée et le rattache au cours.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'cours', 'description' => 'La séquence ou la séance visée (son titre).', 'required' => true],
            ['name' => 'questions', 'description' => 'Le nombre de questions (15 par défaut).', 'required' => false],
            ['name' => 'public', 'description' => 'Le public : classe, niveau, option.', 'required' => false],
        ];
    }

    public function render(array $arguments): string
    {
        $count = ctype_digit($arguments['questions'] ?? '') ? (int) $arguments['questions'] : 15;

        return implode("\n", array_filter([
            \sprintf('Prépare un quiz de %d questions sur « %s », dans ma bibliothèque MonCampus.', $count, $arguments['cours'] ?? ''),
            '' !== ($arguments['public'] ?? '') ? \sprintf('Public : %s.', $arguments['public']) : null,
            '1. Trouve la séquence avec library_list (bibliothèque « sequence ») et lis-la avec sequence_get ; lis aussi avec file_read les supports qui y sont déposés.',
            '2. Appelle format_guide avec format « quiz » et suis-le à la lettre : varie les types, gradue la difficulté, écris une explication par question.',
            '3. Vérifie le document avec quiz_validate, puis crée-le avec quiz_create en le rattachant à la séance (seanceId) ou à la séquence (sequenceId).',
            '4. Donne-moi le lien du quiz et dis-moi quelles notions il couvre.',
        ]));
    }
}
