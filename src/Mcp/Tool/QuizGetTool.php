<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Enum\QuizQuestionGap;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\MixedJsonImporter;
use App\Service\QuizQuestionCompleteness;

/**
 * One quiz, whole, in the « moncampus-quiz/1 » format the creation tools take back - so Claude can
 * read a quiz, improve it, and hand the questions to quiz_add_questions, quiz_create or quiz_update.
 *
 * Each question carries its `number` - its position in the quiz, which quiz_update designates it by -
 * and, when its picture is a file of the library, that file as `imageFileId`. A raw key of the
 * bucket never leaves (`imageKey`), since a document carrying one is refused on the way back in.
 * `questions` says beside the document which ones still wait for a picture or for their zones.
 */
final readonly class QuizGetTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private MixedJsonImporter $format,
        private McpLinks $links,
        private QuizQuestionCompleteness $completeness,
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
        return 'Renvoie un quiz de la bibliothèque au format « moncampus-quiz/1 » (toutes ses questions, réponses et explications), avec les séquences et séances auxquelles il est rattaché. Chaque question porte son numéro ("number"), celui que prend quiz_update, et "questions" dit lesquelles attendent encore une image ou des zones.';
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

        $document = $this->format->export($quiz);
        $document['questions'] = [];
        $overview = [];
        foreach (array_values($quiz->getQuestions()->toArray()) as $index => $question) {
            $number = $index + 1;
            $fileId = null !== $question->getImageStorageKey() ? $question->getLibraryNode()?->getId() : null;
            $overview[] = [
                'number' => $number,
                'type' => $question->getType()->value,
                'label' => mb_strimwidth((string) $question->getLabel(), 0, 120, '…'),
                'hasImage' => null !== $question->getImageStorageKey(),
                'imageFileId' => $fileId,
                'waitingFor' => match ($this->completeness->gapOf($question)) {
                    QuizQuestionGap::Media => 'image',
                    QuizQuestionGap::Zones => 'image ou zones',
                    null => null,
                },
            ];

            $exported = $this->format->exportQuestion($question);
            if (null === $exported) {
                continue;
            }
            if (\is_array($exported['support'] ?? null)) {
                unset($exported['support']['imageKey']);
            }
            $document['questions'][] = ['number' => $number] + $exported + (null !== $fileId ? ['imageFileId' => $fileId] : []);
        }

        return McpToolResult::data(
            \sprintf('Quiz « %s » (%d questions) : %s', $quiz->getName(), $quiz->getQuestions()->count(), $this->links->quiz($quiz)),
            [
                'id' => $quiz->getId(),
                'url' => $this->links->quiz($quiz),
                'attachedToSequences' => $sequences,
                'attachedToSeances' => $seances,
                'questions' => $overview,
                'document' => $document,
            ],
        );
    }
}
