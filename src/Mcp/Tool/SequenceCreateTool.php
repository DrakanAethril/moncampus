<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpSequenceDocuments;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Repository\SequenceTemplateRepository;
use App\Service\SequenceImportWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a séquence - its séances and their phases - from a « moncampus-sequence/1 » document,
 * through the assistant's own writer (App\Service\SequenceImportWriter). It lands last in the folder
 * it is filed in, the way the manual form files one.
 */
final readonly class SequenceCreateTool implements McpTool
{
    public function __construct(
        private McpSequenceDocuments $documents,
        private McpLibraryAccess $library,
        private SequenceImportWriter $writer,
        private SequenceTemplateRepository $sequences,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'sequence_create';
    }

    public function title(): string
    {
        return 'Créer une séquence';
    }

    public function description(): string
    {
        return 'Crée une séquence pédagogique (séances et phases) dans la bibliothèque de l\'enseignant à partir d\'un document « moncampus-sequence/1 » (voir format_guide). Facultatif : la ranger dans un dossier (folderId). Renvoie l\'identifiant de chaque séance créée, à utiliser pour rattacher des quiz ou des supports.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => ['type' => 'object', 'description' => 'Le document « moncampus-sequence/1 » complet.'],
                'folderId' => ['type' => 'integer', 'description' => 'Dossier de séquences où la ranger (voir library_list). Absent : à la racine.'],
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
        return [Feature::SequenceLibrary, Feature::SequenceImport];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $payload = $this->documents->read($call->documentJson('document'));
        $folder = $this->library->sequenceFolder($call->optionalId('folderId'));

        $sequence = $this->writer->createSequence($call->user, $payload, $this->sequences->maxOrderIn($call->user, $folder) + 1, $folder);
        $this->entityManager->flush();

        $seances = [];
        foreach ($sequence->getSeanceTemplates() as $seance) {
            $seances[] = ['id' => $seance->getId(), 'order' => $seance->getOrdre(), 'title' => $seance->getTitre(), 'url' => $this->links->seance($seance)];
        }

        return McpToolResult::data(
            \sprintf('Séquence « %s » créée avec %d séances : %s', $sequence->getTitre(), \count($seances), $this->links->sequence($sequence)),
            ['id' => $sequence->getId(), 'url' => $this->links->sequence($sequence), 'seances' => $seances] + $this->documents->summary($payload),
            ['kind' => 'sequence', 'id' => (int) $sequence->getId()],
        );
    }
}
