<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpCrm;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Service\Crm\CrmDossierImport;
use App\Service\JsonRequestPayload;

/**
 * The import of the team's spreadsheet, line by line, through Claude: each line ready becomes a
 * dossier, each line refused comes back with its reference and its reasons - to be talked over and
 * sent again once settled. A candidate already in the formation is ignored, never updated, so a
 * second send of the same lines is harmless.
 */
final readonly class CrmImportTool implements McpTool
{
    public function __construct(private CrmDossierImport $import)
    {
    }

    public function name(): string
    {
        return 'crm_import';
    }

    public function title(): string
    {
        return 'Importer des lignes du classeur des inscriptions';
    }

    public function description(): string
    {
        return 'Importe dans le CRM des inscriptions des lignes du classeur de l\'équipe, une ligne par dossier, décrites comme pour crm_dossier_create avec en plus `ref` (où est la ligne, ex. « SIO 1, ligne 12 », rendu tel quel). Chaque ligne est vérifiée en entier : une ligne refusée n\'écrit rien et revient dans `errors` avec sa référence et toutes ses raisons ; un candidat déjà présent dans la formation (même nom) est une ligne `ignored`, jamais mise à jour ; les autres deviennent des dossiers (`ready`). Une pièce sans date est faite, date inconnue (« repris du classeur ») ; une ligne sans modalité prend celle de la formation ; une origine doit être de la liste (sinon « Inconnue »). Commence par `dryRun: true` et montre les erreurs à l\'enseignant avant d\'importer pour de bon. Au plus 300 lignes par appel.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rows' => ['type' => 'array', 'items' => McpCrm::dossierSchema(true)],
                'dryRun' => ['type' => 'boolean', 'default' => false, 'description' => 'Vérifier sans rien écrire.'],
            ],
            'required' => ['rows'],
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::Crm];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $value = $call->arguments->toArray()['rows'] ?? null;
        $rows = \is_string($value) ? JsonRequestPayload::listFromJson($value) : $call->arguments->objects('rows');
        if ([] === $rows) {
            throw new McpToolException('`rows` est vide.');
        }
        if (\count($rows) > CrmDossierImport::MAX_ROWS) {
            throw new McpToolException(\sprintf('%d lignes : envoyez-en au plus %d par appel.', \count($rows), CrmDossierImport::MAX_ROWS));
        }

        $dryRun = $call->arguments->bool('dryRun');
        $result = $this->import->import($rows, $dryRun, $call->user);

        return McpToolResult::data(\sprintf(
            '%s : %d ligne(s) %s, %d en erreur, %d ignorée(s) (déjà dans le CRM).',
            $dryRun ? 'Vérification' : 'Import',
            \count($result['ready']),
            $dryRun ? 'prête(s) à importer' : 'importée(s)',
            \count($result['errors']),
            \count($result['ignored']),
        ), ['dryRun' => $dryRun] + $result);
    }
}
