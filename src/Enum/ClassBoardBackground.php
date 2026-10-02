<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The five backgrounds of a virtual board (design/validated/tableau-virtuel.md, §3).
 *
 * The background belongs to the board, never to the viewer's light/dark theme: a board is chosen
 * for the room it is projected in, not for the laptop it was prepared on. The value is what the
 * page writes into `data-background`, and app.css draws each one.
 */
enum ClassBoardBackground: string
{
    case Slate = 'slate';
    case Navy = 'navy';
    case Chalkboard = 'chalkboard';
    case Light = 'light';
    case Grid = 'grid';

    public function labelKey(): string
    {
        return match ($this) {
            self::Slate => 'classBoardBackgroundSlateLabel',
            self::Navy => 'classBoardBackgroundNavyLabel',
            self::Chalkboard => 'classBoardBackgroundChalkboardLabel',
            self::Light => 'classBoardBackgroundLightLabel',
            self::Grid => 'classBoardBackgroundGridLabel',
        };
    }

    /**
     * The swatch drawn in the background picker - a flat colour standing for the gradient.
     */
    public function swatch(): string
    {
        return match ($this) {
            self::Slate => '#24343f',
            self::Navy => '#12344d',
            self::Chalkboard => '#26473b',
            self::Light => '#e9eef3',
            self::Grid => '#fbfcfd',
        };
    }
}
