<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpSeriousGames;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;

/**
 * One version of a unit's template - its JSON definition as written, and questions drawn from it
 * (design/validated/serious-games.md §9). Administrators only.
 */
final readonly class SeriousGameTemplateGetTool implements McpTool
{
    public function __construct(private McpSeriousGames $seriousGames)
    {
    }

    public function name(): string
    {
        return 'serious_game_template_get';
    }

    public function title(): string
    {
        return 'Lire un gabarit de serious game';
    }

    public function description(): string
    {
        return 'Réservé aux administrateurs. Renvoie la définition JSON du gabarit d\'une unité (sa version courante, ou la version demandée), son statut, son dernier banc, et 5 questions générées avec leur réponse et les erreurs typiques qu\'elles reconnaissent. Point de départ pour proposer une nouvelle version.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'game' => ['type' => 'string', 'description' => 'Identifiant du jeu (« observatoire » par défaut).'],
                'unit' => ['type' => 'string', 'description' => 'Identifiant de l\'unité (serious_game_template_list), par exemple « taux ».'],
                'version' => ['type' => 'integer', 'description' => 'Numéro de version ; la version courante si absent.'],
            ],
            'required' => ['unit'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::SeriousGames];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->seriousGames->assertAdministrator();
        $template = $this->seriousGames->template($call, $this->seriousGames->game($call));

        return McpToolResult::data(
            \sprintf('Gabarit « %s », version %d (%s).', $template->getUnitCode(), $template->getVersion(), $template->getStatus()->value),
            $this->seriousGames->describe($template) + ['definition' => $template->getDefinition(), 'samples' => $this->seriousGames->samples($template, 5)],
        );
    }
}
