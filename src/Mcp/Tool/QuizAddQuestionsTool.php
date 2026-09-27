<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpQuizDocuments;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Service\MixedJsonImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Appends questions to an existing quiz - never replaces one: the questions already there, and the
 * attempts made on them, stay as they are.
 */
final readonly class QuizAddQuestionsTool implements McpTool
{
    public function __construct(
        private McpQuizDocuments $documents,
        private McpLibraryAccess $library,
        private MixedJsonImporter $importer,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'quiz_add_questions';
    }

    public function title(): string
    {
        return 'Ajouter des questions à un quiz';
    }

    public function description(): string
    {
        return 'Ajoute des questions à la fin d\'un quiz existant de la bibliothèque. Chaque question a la forme d\'un élément de "questions" du format « moncampus-quiz/1 ». Les questions existantes ne sont ni modifiées ni supprimées. Tout est refusé à la moindre question invalide.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quizId' => ['type' => 'integer'],
                'questions' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Les questions à ajouter, au format « moncampus-quiz/1 ».'],
            ],
            'required' => ['quizId', 'questions'],
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::QuizLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $quiz = $this->library->quiz($call->requiredId('quizId'));
        $questions = $call->arguments->toArray()['questions'] ?? null;
        if (!\is_array($questions) || [] === $questions) {
            throw new McpToolException('L\'argument « questions » est obligatoire : une liste de questions au format « moncampus-quiz/1 ».');
        }

        $payload = $this->documents->readStrictly(json_encode([
            'format' => MixedJsonImporter::FORMAT,
            'questions' => array_values($questions),
        ], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        $before = $quiz->getQuestions()->count();
        $this->importer->appendQuestions($quiz, $payload['questions']);
        $quiz->setLastUpdatedBy($call->user);
        $quiz->setLastUpdatedDate($this->clock->now());
        $this->entityManager->flush();

        $added = $quiz->getQuestions()->count() - $before;

        return McpToolResult::data(
            \sprintf('%d questions ajoutées au quiz « %s », qui en compte maintenant %d : %s', $added, $quiz->getName(), $quiz->getQuestions()->count(), $this->links->quiz($quiz)),
            ['id' => $quiz->getId(), 'url' => $this->links->quiz($quiz), 'added' => $added, 'questionCount' => $quiz->getQuestions()->count()],
        );
    }
}
