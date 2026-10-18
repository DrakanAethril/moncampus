<?php

declare(strict_types=1);

namespace App\SeriousGame\Engine;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameTemplate;
use App\Repository\SeriousGameTemplateRepository;
use App\SeriousGame\Template\TemplateDefinition;

/**
 * A game's templates as the students are served them: the validated versions of the database. A
 * draft is never current - it serves nobody until it is validated - but any version, superseded
 * included, is found again to replay a question it served.
 */
final class DatabaseTemplateSource implements TemplateSource
{
    /** @var array<string, SeriousGameTemplate>|null */
    private ?array $current = null;

    public function __construct(
        private readonly SeriousGame $game,
        private readonly SeriousGameTemplateRepository $templates,
    ) {
    }

    public function current(string $unit): ?TemplateDefinition
    {
        $this->current ??= $this->templates->findCurrentByUnit($this->game);
        $template = $this->current[$unit] ?? null;

        return null === $template ? null : self::definition($template);
    }

    public function version(string $unit, int $version): ?TemplateDefinition
    {
        $template = $this->templates->findVersion($this->game, $unit, $version);

        return null === $template ? null : self::definition($template);
    }

    public static function definition(SeriousGameTemplate $template): TemplateDefinition
    {
        return new TemplateDefinition($template->getUnitCode(), $template->getVersion(), $template->getDefinition());
    }
}
