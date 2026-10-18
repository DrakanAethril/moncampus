<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpSeriousGames;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\SeriousGame\SeriousGameTemplates;

/**
 * Runs the bench of a template version again (design/validated/serious-games.md §5.3, §9) and stores
 * the report on it. Administrators only.
 */
final readonly class SeriousGameTemplateBenchTool implements McpTool
{
    public function __construct(private McpSeriousGames $seriousGames)
    {
    }

    public function name(): string
    {
        return 'serious_game_template_bench';
    }

    public function title(): string
    {
        return 'Repasser un gabarit au banc';
    }

    public function description(): string
    {
        return 'Réservé aux administrateurs. Relance le banc d\'une version de gabarit (un brouillon, ou une version déjà validée) sur le nombre de graines demandé (2000 au plus, 1000 par défaut) et enregistre le rapport sur la version.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'game' => ['type' => 'string', 'description' => 'Identifiant du jeu (« observatoire » par défaut).'],
                'unit' => ['type' => 'string'],
                'version' => ['type' => 'integer', 'description' => 'Numéro de version ; la version courante si absent.'],
                'seeds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => SeriousGameTemplates::MAX_SEEDS],
            ],
            'required' => ['unit'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::SeriousGames];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->seriousGames->assertAdministrator();
        $template = $this->seriousGames->template($call, $this->seriousGames->game($call));
        $report = $this->seriousGames->versions()->bench($template, $call->arguments->int('seeds') ?? SeriousGameTemplates::PROPOSE_SEEDS);

        return McpToolResult::data(
            \sprintf('Banc de « %s » v%d : %s.', $template->getUnitCode(), $template->getVersion(), true === $report['green'] ? 'au vert' : 'ROUGE'),
            $this->seriousGames->describe($template),
        );
    }
}
