<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\MixedJsonImporter;

/**
 * One quiz, whole, in the « moncampus-quiz/1 » format the creation tools take back - so Claude can
 * read a quiz, improve it, and hand the questions to quiz_add_questions or quiz_create.
 */
final readonly class QuizGetTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private MixedJsonImporter $format,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'quiz_get';
    }

    public function title(): string
    {
        return 'Lire un quiz';
    }

    public function description(): string
    {
        return 'Renvoie un quiz de la bibliothèque au format « moncampus-quiz/1 » (toutes ses questions, réponses et explications), avec les séquences et séances auxquelles il est rattaché.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quizId' => ['type' => 'integer', 'description' => 'Identifiant du quiz (voir library_list).'],
            ],
            'required' => ['quizId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::QuizLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $quiz = $this->library->quiz($call->requiredId('quizId'), QuizTemplateVoter::VIEW);

        $sequences = [];
        foreach ($quiz->getSequenceTemplates() as $sequence) {
            $sequences[] = ['id' => $sequence->getId(), 'title' => $sequence->getTitre()];
        }
        $seances = [];
        foreach ($quiz->getSeanceTemplates() as $seance) {
            $seances[] = ['id' => $seance->getId(), 'title' => $seance->getTitre(), 'sequenceId' => $seance->getSequenceTemplate()?->getId()];
        }

        return McpToolResult::data(
            \sprintf('Quiz « %s » (%d questions) : %s', $quiz->getName(), $quiz->getQuestions()->count(), $this->links->quiz($quiz)),
            [
                'id' => $quiz->getId(),
                'url' => $this->links->quiz($quiz),
                'attachedToSequences' => $sequences,
                'attachedToSeances' => $seances,
                'document' => $this->format->export($quiz),
            ],
        );
    }
}
