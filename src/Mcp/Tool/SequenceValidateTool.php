<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpSequenceDocuments;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;

/**
 * A dry run of sequence_create: the document read by the assistant's reader, nothing written.
 */
final readonly class SequenceValidateTool implements McpTool
{
    public function __construct(private McpSequenceDocuments $documents)
    {
    }

    public function name(): string
    {
        return 'sequence_validate';
    }

    public function title(): string
    {
        return 'Vérifier une séquence';
    }

    public function description(): string
    {
        return 'Vérifie un document « moncampus-sequence/1 » sans rien créer : renvoie le nombre de séances et de phases, les avertissements (durées sans unité, champs trop longs…) et le rapport de transposition. À utiliser avant sequence_create.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => ['type' => 'object', 'description' => 'Le document « moncampus-sequence/1 » complet (voir format_guide).'],
            ],
            'required' => ['document'],
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::SequenceLibrary, Feature::SequenceImport];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $payload = $this->documents->read($call->documentJson('document'));
        $summary = $this->documents->summary($payload);

        return McpToolResult::data(
            \sprintf('Document lisible : « %s », %d séances, %d avertissements.', $payload['sequence']['titre'], $payload['counts']['seances'], \count($payload['warnings'])),
            ['title' => $payload['sequence']['titre']] + $summary,
        );
    }
}
