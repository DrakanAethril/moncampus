<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The six backgrounds of a virtual board (design/validated/tableau-virtuel.md, §3).
 *
 * The background belongs to the board, never to the viewer's light/dark theme: a board is chosen
 * for the room it is projected in, not for the laptop it was prepared on. The value is what the
 * page writes into `data-background`, and app.css draws each one.
 *
 * « Nature » is the default and the only photograph - a new one each day, drawn from Wikimedia
 * Commons by `app:class-board:photo` and served from the uploads bucket
 * (App\Service\ClassBoard\ClassBoardPhotoOfTheDay), never fetched from a third party at display: the
 * projector must not send every classroom's address to an image service. Without one yet, the CC0
 * photograph shipped with the application (assets/images/class-board/, credited in NOTICE).
 */
enum ClassBoardBackground: string
{
    case Nature = 'nature';
    case Slate = 'slate';
    case Navy = 'navy';
    case Chalkboard = 'chalkboard';
    case Light = 'light';
    case Grid = 'grid';

    public function labelKey(): string
    {
        return match ($this) {
            self::Nature => 'classBoardBackgroundNatureLabel',
            self::Slate => 'classBoardBackgroundSlateLabel',
            self::Navy => 'classBoardBackgroundNavyLabel',
            self::Chalkboard => 'classBoardBackgroundChalkboardLabel',
            self::Light => 'classBoardBackgroundLightLabel',
            self::Grid => 'classBoardBackgroundGridLabel',
        };
    }

    /**
     * The swatch drawn in the background picker - a flat colour standing for the gradient. The
     * photograph's swatch is the photograph itself, drawn by app.css over this colour.
     */
    public function swatch(): string
    {
        return match ($this) {
            self::Nature => '#4a5a34',
            self::Slate => '#24343f',
            self::Navy => '#12344d',
            self::Chalkboard => '#26473b',
            self::Light => '#e9eef3',
            self::Grid => '#fbfcfd',
        };
    }
}
