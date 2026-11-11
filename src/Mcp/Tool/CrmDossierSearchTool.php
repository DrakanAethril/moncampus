<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Service\Crm\CrmSearch;

/** « Rechercher un dossier par nom ou prénom », as the CRM's home does. */
final readonly class CrmDossierSearchTool implements McpTool
{
    public function __construct(private McpCrm $crm, private CrmSearch $search)
    {
    }

    public function name(): string
    {
        return 'crm_dossier_search';
    }

    public function title(): string
    {
        return 'Rechercher un dossier d\'inscription';
    }

    public function description(): string
    {
        return 'Cherche les dossiers du CRM des inscriptions par nom et prénom (chaque mot tapé commence un mot du nom, accents ignorés), dans les années ouvertes ; ceux de l\'année demandée (l\'année par défaut sans `year`) d\'abord.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['query' => ['type' => 'string'], 'year' => ['type' => 'string']], 'required' => ['query']];
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
        $hits = \array_slice($this->search->find($call->requiredString('query'), $year), 0, 30);

        return McpToolResult::data(\sprintf('%d dossier(s) trouvé(s).', \count($hits)), ['dossiers' => array_map($this->crm->summary(...), $hits)]);
    }
}
