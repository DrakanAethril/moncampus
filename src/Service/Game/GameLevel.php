<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * One of the six levels of the cursus, with the two tints its ring and its band are drawn from.
 *
 * The values come from design/design_handoff_gamification/data/gamification.json and are not
 * reinvented here: the handoff's visual half is taken over whole, only its points mechanics were
 * replaced (design/validated/gamification.md, preamble).
 *
 * There is no image anywhere: the ring is a `conic-gradient`, the halo a `box-shadow` of growing
 * opacity (.45 -> .70) and the band a filled pill. Level 6 alone adds a golden `outline` at 3 px of
 * offset - which is why $outline is nullable rather than empty on the other five.
 */
final readonly class GameLevel
{
    public function __construct(
        public int $level,
        public int $xpMin,
        public string $dark,
        public string $light,
        public float $haloAlpha,
        public string $bandBackground,
        public string $bandText,
        public ?string $outline = null,
        public bool $legendary = false,
    ) {
    }

    /** `rgba(r, g, b, alpha)` off $dark - the halo, which CSS cannot derive from a hex on its own. */
    public function haloColor(): string
    {
        [$r, $g, $b] = sscanf($this->dark, '#%02x%02x%02x') ?? [0, 0, 0];

        return \sprintf('rgba(%d, %d, %d, %s)', (int) $r, (int) $g, (int) $b, rtrim(rtrim(number_format($this->haloAlpha, 2, '.', ''), '0'), '.'));
    }
}
