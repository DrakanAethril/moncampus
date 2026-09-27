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
use App\Service\QuizQuestionCompleteness;
use App\Service\QuizTemplateImportWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a quiz in the teacher's library from a « moncampus-quiz/1 » document - the same writer as
 * the import screens (App\Service\QuizTemplateImportWriter), so a quiz made from Claude is the quiz
 * the screen would have made. Nothing is launched: the quiz sits in the library until the teacher
 * launches it to a class.
 */
final readonly class QuizCreateTool implements McpTool
{
    public function __construct(
        private McpQuizDocuments $documents,
        private McpLibraryAccess $library,
        private QuizTemplateImportWriter $writer,
        private MixedJsonImporter $importer,
        private QuizQuestionCompleteness $completeness,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'quiz_create';
    }

    public function title(): string
    {
        return 'Créer un quiz';
    }

    public function description(): string
    {
        return 'Crée un quiz dans la bibliothèque de l\'enseignant à partir d\'un document « moncampus-quiz/1 » (voir format_guide). Le document est refusé en entier à la moindre question invalide, avec la liste des corrections à faire. Facultatif : le ranger dans un dossier (folderId) et le rattacher à une séquence (sequenceId) ou à une séance (seanceId). Le quiz n\'est lancé auprès d\'aucune classe.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => ['type' => 'object', 'description' => 'Le document « moncampus-quiz/1 » complet.'],
                'folderId' => ['type' => 'integer', 'description' => 'Dossier de quiz où le ranger (voir library_list). Absent : à la racine.'],
                'sequenceId' => ['type' => 'integer', 'description' => 'Séquence à laquelle le rattacher.'],
                'seanceId' => ['type' => 'integer', 'description' => 'Séance à laquelle le rattacher (prioritaire sur sequenceId).'],
            ],
            'required' => ['document'],
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
        $payload = $this->documents->readStrictly($call->documentJson('document'));
        if ([] === $payload['questions']) {
            throw new McpToolException('Le document ne contient aucune question.');
        }

        $folder = $this->library->quizFolder($call->optionalId('folderId'));
        $seanceId = $call->optionalId('seanceId');
        $sequenceId = $call->optionalId('sequenceId');
        $attachTo = null !== $seanceId
            ? $this->library->seance($seanceId)
            : (null !== $sequenceId ? $this->library->sequence($sequenceId) : null);

        $quiz = $this->writer->newTemplate($call->user, $folder, $payload['name'], $payload['subject'], $payload['description'], \count($payload['questions']));
        $this->writer->fill($quiz, $this->importer, $payload['questions'], $attachTo);
        $this->entityManager->persist($quiz);
        $this->entityManager->flush();

        $waiting = $this->completeness->countIncomplete($quiz->getQuestions());

        return McpToolResult::data(
            \sprintf('Quiz « %s » créé avec %d questions%s : %s', $quiz->getName(), $quiz->getQuestions()->count(), $waiting > 0 ? \sprintf(' (%d attendent encore une image ou des zones, à compléter dans MonCampus)', $waiting) : '', $this->links->quiz($quiz)),
            [
                'id' => $quiz->getId(),
                'url' => $this->links->quiz($quiz),
                'questionCount' => $quiz->getQuestions()->count(),
                'questionsToComplete' => $waiting,
                'attachedTo' => null === $attachTo ? null : ['kind' => $attachTo instanceof \App\Entity\SeanceTemplate ? 'seance' : 'sequence', 'id' => $attachTo->getId()],
            ],
            ['kind' => 'quiz', 'id' => (int) $quiz->getId()],
        );
    }
}
