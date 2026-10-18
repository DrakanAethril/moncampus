<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpSeriousGames;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\SeriousGame\Template\TemplateFormatException;

/**
 * Proposes a new version of a unit's template, as a **draft** (design/validated/serious-games.md
 * §9): refused whole at its first format error, put on the bench at once, and serving no student
 * until an administrator validates it. Administrators only. Nothing is deleted.
 */
final readonly class SeriousGameTemplateProposeTool implements McpTool
{
    public function __construct(private McpSeriousGames $seriousGames)
    {
    }

    public function name(): string
    {
        return 'serious_game_template_propose';
    }

    public function title(): string
    {
        return 'Proposer une version de gabarit';
    }

    public function description(): string
    {
        return 'Réservé aux administrateurs. Crée un BROUILLON de gabarit pour une unité d\'un serious game, à partir d\'une définition au format « moncampus-gabarit/1 » (appelle format_guide avec `format: "gabarit_serious_game"` avant de l\'écrire, et pars de serious_game_template_get). Le document est refusé entier à la première erreur de format ; accepté, il passe au banc (1000 graines) et l\'outil renvoie le rapport et 10 questions générées. Un brouillon ne sert aucun élève : il devient la version courante avec serious_game_template_validate, banc au vert seulement. Garde les identifiants des erreurs typiques existantes.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'game' => ['type' => 'string', 'description' => 'Identifiant du jeu : « observatoire » (par défaut) ou « pompei ».'],
                'unit' => ['type' => 'string', 'description' => 'Identifiant de l\'unité ; il doit être aussi celui du champ « unit » de la définition.'],
                'definition' => ['description' => 'Le gabarit complet (objet JSON, ou texte JSON).'],
                'note' => ['type' => 'string', 'description' => 'Ce qui change par rapport à la version courante, en une phrase (lu par les administrateurs).'],
            ],
            'required' => ['unit', 'definition'],
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
        $game = $this->seriousGames->game($call);
        $unit = $call->requiredString('unit');
        $document = json_decode($call->documentJson('definition'), true);
        if (!\is_array($document)) {
            throw new McpToolException('La définition n\'est pas un objet JSON lisible.');
        }

        try {
            $draft = $this->seriousGames->versions()->propose($game, $unit, $document, $call->user, trim($call->arguments->string('note')) ?: null);
        } catch (TemplateFormatException $e) {
            throw new McpToolException('Gabarit refusé — '.$e->getMessage(), ['Élément en cause : '.$e->path]);
        } catch (\DomainException $e) {
            throw new McpToolException($e->getMessage());
        }

        $describe = $this->seriousGames->describe($draft);
        $green = $draft->isBenchGreen();

        return McpToolResult::data(
            \sprintf('Brouillon v%d du gabarit « %s » créé ; banc %s.%s', $draft->getVersion(), $unit, $green ? 'au vert' : 'ROUGE', $green ? ' Il peut être validé (serious_game_template_validate).' : ' Corrige les écarts du rapport et propose une nouvelle version.'),
            $describe + ['samples' => $this->seriousGames->samples($draft, 10)],
            ['kind' => 'serious_game_template', 'id' => (int) $draft->getId()],
        );
    }
}
