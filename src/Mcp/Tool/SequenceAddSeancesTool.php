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
use App\Service\SequenceImportWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Appends the séances of a document to an existing séquence - the assistant's « ajouter à une
 * séquence » destination. Its own fields, and the séances already there, are left as they are.
 */
final readonly class SequenceAddSeancesTool implements McpTool
{
    public function __construct(
        private McpSequenceDocuments $documents,
        private McpLibraryAccess $library,
        private SequenceImportWriter $writer,
        private EntityManagerInterface $entityManager,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'sequence_add_seances';
    }

    public function title(): string
    {
        return 'Ajouter des séances à une séquence';
    }

    public function description(): string
    {
        return 'Ajoute à la fin d\'une séquence existante les séances d\'un document « moncampus-sequence/1 » (le bloc "sequence" du document est ignoré). Les séances existantes ne sont pas modifiées.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sequenceId' => ['type' => 'integer'],
                'document' => ['type' => 'object', 'description' => 'Un document « moncampus-sequence/1 » dont seules les séances sont reprises.'],
            ],
            'required' => ['sequenceId', 'document'],
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
        $sequence = $this->library->sequence($call->requiredId('sequenceId'));
        $payload = $this->documents->read($call->documentJson('document'));
        $before = $sequence->getSeanceTemplates()->count();

        $this->writer->appendSeances($sequence, $payload);
        $this->entityManager->flush();

        $added = [];
        // The writer appends to the collection, so what it added is what follows the rows there were.
        foreach (\array_slice(array_values($sequence->getSeanceTemplates()->toArray()), $before) as $seance) {
            $added[] = ['id' => $seance->getId(), 'order' => $seance->getOrdre(), 'title' => $seance->getTitre(), 'url' => $this->links->seance($seance)];
        }

        return McpToolResult::data(
            \sprintf('%d séances ajoutées à la séquence « %s » : %s', \count($added), $sequence->getTitre(), $this->links->sequence($sequence)),
            ['id' => $sequence->getId(), 'url' => $this->links->sequence($sequence), 'addedSeances' => $added] + $this->documents->summary($payload),
        );
    }
}
