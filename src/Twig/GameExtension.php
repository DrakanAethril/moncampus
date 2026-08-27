<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\Game\GameBadge;
use App\Service\Game\GameBadgeProvider;
use App\Service\Game\GameLevel;
use App\Service\Game\GameLevels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the templates of the campus game need and cannot compute: the badge of the person looking at
 * the page, and the tints of a level.
 *
 * `game_level_style()` returns a ready-made `style` attribute rather than six classes, because the
 * ring is a `conic-gradient` and the halo a `box-shadow` whose alpha changes level by level - values
 * that live in App\Service\Game\GameLevels and must not be transcribed a second time into a
 * stylesheet. The rest of the look is `cm-gm-*` classes, and there is no image anywhere.
 */
class GameExtension extends AbstractExtension
{
    public function __construct(private readonly GameBadgeProvider $badges)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_badge', $this->badge(...)),
            new TwigFunction('game_level_style', $this->levelStyle(...), ['is_safe' => ['html_attr']]),
            new TwigFunction('game_band_style', $this->bandStyle(...), ['is_safe' => ['html_attr']]),
        ];
    }

    public function badge(?User $user): ?GameBadge
    {
        return $this->badges->forUser($user);
    }

    /** The ring and its halo, for one level - the two things a stylesheet cannot derive from a hex. */
    public function levelStyle(int $level): string
    {
        $entry = GameLevels::at($level);

        return \sprintf(
            'background: conic-gradient(%1$s, %2$s, %3$s, %2$s, %1$s); box-shadow: 0 0 %4$dpx %5$s;%6$s',
            $entry->dark,
            $entry->light,
            $this->darken($entry->dark),
            14 + 2 * ($level - 1),
            $entry->haloColor(),
            // Level 6 alone carries the golden outline, at 3 px of offset.
            null === $entry->outline ? '' : \sprintf(' outline: 2px solid %s; outline-offset: 3px;', $entry->outline),
        );
    }

    /** The « NIVEAU n » pill: dark fill, light text, light border. */
    public function bandStyle(int $level): string
    {
        $entry = GameLevels::at($level);

        return \sprintf(
            'background: %s; color: %s; border: 1px solid %s;',
            $entry->bandBackground,
            $entry->bandText,
            $entry->light,
        );
    }

    /** A darker turn of the same hue, so the conic gradient reads as metal rather than as a wash. */
    private function darken(string $hex): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?? [0, 0, 0];

        return \sprintf('#%02x%02x%02x', (int) ((int) $r * 0.72), (int) ((int) $g * 0.72), (int) ((int) $b * 0.72));
    }

    /** @return list<GameLevel> */
    public function levels(): array
    {
        return GameLevels::all();
    }
}
