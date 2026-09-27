<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Service\SequenceQuizLinker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Associer un quiz » of the séquence and séance screens (App\Service\SequenceQuizLinker): a link,
 * never a move - the quiz stays in its folder of the library.
 */
final readonly class QuizLinkTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private SequenceQuizLinker $linker,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'quiz_link';
    }

    public function title(): string
    {
        return 'Rattacher un quiz à une séquence ou une séance';
    }

    public function description(): string
    {
        return 'Rattache un quiz de la bibliothèque à une séquence (sequenceId) ou à une séance (seanceId). Le quiz reste rangé où il est ; rattacher deux fois ne crée pas de doublon.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quizId' => ['type' => 'integer'],
                'sequenceId' => ['type' => 'integer'],
                'seanceId' => ['type' => 'integer'],
            ],
            'required' => ['quizId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::QuizLibrary, Feature::SequenceLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $quiz = $this->library->quiz($call->requiredId('quizId'));
        $seanceId = $call->optionalId('seanceId');
        $sequenceId = $call->optionalId('sequenceId');

        if (null !== $seanceId) {
            $seance = $this->library->seance($seanceId);
            $this->linker->attachToSeance($quiz, $seance);
            $this->entityManager->flush();

            return McpToolResult::data(\sprintf('Quiz « %s » rattaché à la séance « %s » : %s', $quiz->getName(), $seance->getTitre(), $this->links->seance($seance)), ['quizId' => $quiz->getId(), 'seanceId' => $seance->getId()]);
        }

        if (null !== $sequenceId) {
            $sequence = $this->library->sequence($sequenceId);
            $this->linker->attachToSequence($quiz, $sequence);
            $this->entityManager->flush();

            return McpToolResult::data(\sprintf('Quiz « %s » rattaché à la séquence « %s » : %s', $quiz->getName(), $sequence->getTitre(), $this->links->sequence($sequence)), ['quizId' => $quiz->getId(), 'sequenceId' => $sequence->getId()]);
        }

        throw new McpToolException('Indiquez la séquence (sequenceId) ou la séance (seanceId) où rattacher le quiz.');
    }
}
