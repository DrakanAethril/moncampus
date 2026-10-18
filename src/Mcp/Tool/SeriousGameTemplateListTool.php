<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\SeriousGameTemplate;
use App\Enum\Feature;
use App\Mcp\McpSeriousGames;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Repository\SeriousGameTemplateRepository;
use App\SeriousGame\SeriousGamePlay;

/**
 * The units of a serious game with their template versions (design/validated/serious-games.md §9) -
 * where the other template tools start. Administrators only.
 */
final readonly class SeriousGameTemplateListTool implements McpTool
{
    public function __construct(
        private McpSeriousGames $seriousGames,
        private SeriousGameTemplateRepository $templates,
    ) {
    }

    public function name(): string
    {
        return 'serious_game_template_list';
    }

    public function title(): string
    {
        return 'Lister les gabarits d\'un serious game';
    }

    public function description(): string
    {
        return 'Réservé aux administrateurs. Renvoie les unités d\'un serious game (par défaut « observatoire »), regroupées par chapitre : leur version courante (celle que les élèves reçoivent), son statut, la date et le résultat de son dernier banc, et les brouillons en attente.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['game' => ['type' => 'string', 'description' => 'Identifiant du jeu (« observatoire » par défaut).']],
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
        $game = $this->seriousGames->game($call);
        $versions = [];
        foreach ($this->templates->findForGame($game) as $template) {
            $versions[$template->getUnitCode()][] = $template;
        }

        $chapters = [];
        foreach (SeriousGamePlay::manifest($game)->chapters() as $chapter) {
            $units = [];
            foreach ($chapter['units'] as $unit) {
                $list = $versions[$unit['code']] ?? [];
                $current = array_values(array_filter($list, static fn (SeriousGameTemplate $t): bool => $t->isValidated()))[0] ?? null;
                $units[] = [
                    'unit' => $unit['code'],
                    'label' => $unit['label'],
                    'current' => null === $current ? null : $this->seriousGames->describe($current),
                    'drafts' => array_map($this->seriousGames->describe(...), array_values(array_filter($list, static fn (SeriousGameTemplate $t): bool => $t->isDraft()))),
                ];
            }
            $chapters[] = ['chapter' => $chapter['code'], 'label' => $chapter['label'], 'units' => $units];
        }

        return McpToolResult::data(\sprintf('%s : %d unités.', $game->getTitle(), \count(array_merge(...array_column($chapters, 'units') ?: [[]]))), ['game' => $game->getSlug(), 'chapters' => $chapters]);
    }
}
