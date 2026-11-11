<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;

/** A dossier whole: the candidate, where it stands, every piece asked and entered, the journal. */
final readonly class CrmDossierGetTool implements McpTool
{
    public function __construct(private McpCrm $crm)
    {
    }

    public function name(): string
    {
        return 'crm_dossier_get';
    }

    public function title(): string
    {
        return 'Lire un dossier d\'inscription';
    }

    public function description(): string
    {
        return 'Lit un dossier du CRM des inscriptions : le candidat et ses responsables, la formation, la modalité, la situation, l\'étape, chaque pièce demandée (faite, en cours, à faire) avec sa saisie, les saisies conservées et le journal.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['dossierId' => ['type' => 'integer']], 'required' => ['dossierId']];
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
        $dossier = $this->crm->dossier($call->requiredId('dossierId'));

        return McpToolResult::data(\sprintf('Dossier de %s.', $dossier->getCandidate()->getFullName()), $this->crm->describe($dossier));
    }
}
