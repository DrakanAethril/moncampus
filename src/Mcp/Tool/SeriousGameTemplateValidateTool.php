<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpSeriousGames;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;

/**
 * Makes a draft whose bench is green the unit's current version (design/validated/serious-games.md
 * §9): the students are served it from now on, and the previous version is superseded - kept, to
 * replay the questions it served. Administrators only.
 */
final readonly class SeriousGameTemplateValidateTool implements McpTool
{
    public function __construct(private McpSeriousGames $seriousGames)
    {
    }

    public function name(): string
    {
        return 'serious_game_template_validate';
    }

    public function title(): string
    {
        return 'Valider un brouillon de gabarit';
    }

    public function description(): string
    {
        return 'Réservé aux administrateurs. Fait d\'un brouillon dont le banc est au vert la version courante de son unité : les élèves la reçoivent dès maintenant. L\'ancienne version reste (on rejoue avec elle les questions déjà servies). Demande l\'accord de l\'administrateur avant d\'appeler cet outil.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'game' => ['type' => 'string', 'description' => 'Identifiant du jeu : « observatoire » (par défaut) ou « pompei ».'],
                'unit' => ['type' => 'string'],
                'version' => ['type' => 'integer', 'description' => 'Numéro du brouillon à valider.'],
            ],
            'required' => ['unit', 'version'],
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

        try {
            $this->seriousGames->versions()->validate($template, $call->user);
        } catch (\DomainException $e) {
            throw new McpToolException($e->getMessage());
        }

        return McpToolResult::data(
            \sprintf('La version %d de « %s » est désormais servie aux élèves.', $template->getVersion(), $template->getUnitCode()),
            $this->seriousGames->describe($template),
        );
    }
}
