<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameTemplate;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameTemplateRepository;
use App\SeriousGame\Engine\DatabaseTemplateSource;
use App\SeriousGame\SeriousGamePlay;
use App\SeriousGame\SeriousGameTemplates;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateInterpreter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the serious-game tools of the connector share (design/validated/serious-games.md §9): the
 * administrator check - these tools are **administrators' only**, like the screen - the game and
 * the version an argument names, and the description of a version as the model reads it.
 *
 * The writes go through App\SeriousGame\SeriousGameTemplates, the screen's own door.
 */
final readonly class McpSeriousGames
{
    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private SeriousGameRepository $games,
        private SeriousGameTemplateRepository $templates,
        private SeriousGameTemplates $versions,
        private TemplateInterpreter $interpreter,
        private McpLinks $links,
    ) {
    }

    public function versions(): SeriousGameTemplates
    {
        return $this->versions;
    }

    /** @throws McpToolException */
    public function assertAdministrator(): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) {
            throw new McpToolException('Les gabarits des serious games ne se modifient que par un administrateur.');
        }
    }

    /** @throws McpToolException */
    public function game(McpToolCall $call): SeriousGame
    {
        $slug = trim($call->arguments->string('game', 'observatoire')) ?: 'observatoire';

        return $this->games->findOneBySlug($slug) ?? throw new McpToolException(\sprintf('Aucun serious game « %s ».', $slug));
    }

    /**
     * The version named by `unit` and `version` - the unit's current one when no version is given.
     *
     * @throws McpToolException
     */
    public function template(McpToolCall $call, SeriousGame $game): SeriousGameTemplate
    {
        $unit = $call->requiredString('unit');
        if (null === SeriousGamePlay::manifest($game)->unit($unit)) {
            throw new McpToolException(\sprintf('L\'unité « %s » n\'existe pas dans « %s » (voir serious_game_template_list).', $unit, $game->getSlug()));
        }

        $version = $call->optionalId('version');
        $template = null === $version ? $this->templates->findCurrent($game, $unit) : $this->templates->findVersion($game, $unit, $version);

        return $template ?? throw new McpToolException(\sprintf('Aucune version %s du gabarit « %s ».', $version ?? 'courante', $unit));
    }

    /** @return array<string, mixed> */
    public function describe(SeriousGameTemplate $template): array
    {
        return [
            'unit' => $template->getUnitCode(),
            'version' => $template->getVersion(),
            'status' => $template->getStatus()->value,
            'shippedWithTheGame' => $template->isShippedWithTheGame(),
            'note' => $template->getNote(),
            'bench' => self::bench($template->getBenchReport()),
            'link' => $this->links->url('app_settings_serious_games_template', ['slug' => $template->getGame()->getSlug(), 'unit' => $template->getUnitCode(), 'version' => $template->getVersion()]),
        ];
    }

    /**
     * Questions drawn from a version, as the model reads them: statement, typed answer, the wrong
     * answers it recognises, method.
     *
     * @return list<array<string, mixed>>
     */
    public function samples(SeriousGameTemplate $template, int $count): array
    {
        $definition = DatabaseTemplateSource::definition($template);
        $out = [];
        foreach (TemplateBench::seeds($count) as $seed) {
            try {
                $out[] = TemplateBench::describe($this->interpreter->generate($definition, $seed));
            } catch (\Throwable $e) {
                $out[] = ['seed' => $seed, 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * The bench report, shortened to what the model needs to correct a template.
     *
     * @param array<string, mixed>|null $report
     *
     * @return array<string, mixed>|null
     */
    public static function bench(?array $report): ?array
    {
        if (null === $report) {
            return null;
        }

        return array_intersect_key($report, array_flip(['green', 'seeds', 'questions', 'wrongs', 'recognised', 'maxAttempts', 'failures', 'neverSeen', 'ranAt']));
    }
}
