<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;

/** The CRM's reference for a year: what a dossier is written against. */
final readonly class CrmReferenceTool implements McpTool
{
    public function __construct(private McpCrm $crm)
    {
    }

    public function name(): string
    {
        return 'crm_reference';
    }

    public function title(): string
    {
        return 'Référentiel du CRM des inscriptions';
    }

    public function description(): string
    {
        return 'Donne, pour une année ouverte dans le CRM des inscriptions (l\'année par défaut sans `year`), ce qu\'il faut pour décrire un dossier : les formations suivies (programId, options, modalité par défaut, pièces masquées), les pièces (code, type, valeurs, modalités, parcours), les origines, les modalités, parcours et situations. À appeler avant crm_dossier_create et crm_import. Le connecteur crée et modifie des dossiers, jamais la configuration (années, formations, pièces, origines), qui se règle dans Formatool.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['year' => ['type' => 'string', 'description' => 'Ex. « 2026-2027 ».']]];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::Crm];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $year = $this->crm->year($call->arguments->string('year'));
        $reference = $this->crm->reference($year);

        return McpToolResult::data(\sprintf('Référentiel du CRM pour %s : %d formations suivies, %d pièces.', $reference['year'], \count($reference['programs']), \count($reference['pieces'])), $reference);
    }
}
