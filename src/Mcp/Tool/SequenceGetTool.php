<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\LibraryResource;
use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Security\Voter\SequenceTemplateVoter;
use App\Service\SequenceJsonExporter;
use Doctrine\Common\Collections\Collection;

/**
 * One séquence, whole: the « moncampus-sequence/1 » document the creation tools take back, plus
 * what that document does not carry - the ids of its séances, the quizzes attached at each level,
 * and the resources (files of the library, links) hung on it, which is where the course material
 * Claude should start from usually is.
 */
final readonly class SequenceGetTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private SequenceJsonExporter $exporter,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'sequence_get';
    }

    public function title(): string
    {
        return 'Lire une séquence';
    }

    public function description(): string
    {
        return 'Renvoie une séquence pédagogique au format « moncampus-sequence/1 » (séances, phases, objectifs…), avec l\'identifiant de chaque séance, les quiz rattachés et les ressources déposées sur la séquence, ses séances et leurs phases. Une ressource issue de la bibliothèque de fichiers porte un `fileId` lisible avec file_read ; les autres portent un `resourceId`.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sequenceId' => ['type' => 'integer', 'description' => 'Identifiant de la séquence (voir library_list).'],
            ],
            'required' => ['sequenceId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::SequenceLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $sequence = $this->library->sequence($call->requiredId('sequenceId'), SequenceTemplateVoter::VIEW);

        $seances = [];
        foreach ($sequence->getSeanceTemplates() as $seance) {
            $phases = [];
            foreach ($seance->getSeancePhaseTemplates() as $phase) {
                if (!$phase->getLibraryResources()->isEmpty()) {
                    $phases[] = ['name' => $phase->getNom(), 'resources' => $this->resources($phase->getLibraryResources())];
                }
            }

            $seances[] = [
                'id' => $seance->getId(),
                'order' => $seance->getOrdre(),
                'title' => $seance->getTitre(),
                'url' => $this->links->seance($seance),
                'quizzes' => $this->quizzes($seance->getQuizTemplates()),
                'resources' => $this->resources($seance->getLibraryResources()),
                'phasesWithResources' => $phases,
            ];
        }

        return McpToolResult::data(
            \sprintf('Séquence « %s » (%d séances) : %s', $sequence->getTitre(), \count($seances), $this->links->sequence($sequence)),
            [
                'id' => $sequence->getId(),
                'url' => $this->links->sequence($sequence),
                'quizzes' => $this->quizzes($sequence->getQuizTemplates()),
                'resources' => $this->resources($sequence->getLibraryResources()),
                'seances' => $seances,
                'document' => json_decode($this->exporter->export($sequence), true, 512, \JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @param Collection<int, \App\Entity\QuizTemplate> $quizzes
     *
     * @return list<array{id: ?int, name: ?string}>
     */
    private function quizzes(Collection $quizzes): array
    {
        $rows = [];
        foreach ($quizzes as $quiz) {
            $rows[] = ['id' => $quiz->getId(), 'name' => $quiz->getName()];
        }

        return $rows;
    }

    /**
     * @param Collection<int, LibraryResource> $resources
     *
     * @return list<array<string, mixed>>
     */
    private function resources(Collection $resources): array
    {
        $rows = [];
        foreach ($resources as $resource) {
            $rows[] = [
                'resourceId' => $resource->getId(),
                'label' => $resource->getLabel(),
                'type' => $resource->getType()?->value,
                'fileId' => $resource->getLibraryNode()?->getId(),
                'link' => $resource->getUrl(),
            ];
        }

        return $rows;
    }
}
