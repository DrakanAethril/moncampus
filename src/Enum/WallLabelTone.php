<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The six colours a card's label is drawn in - the pairs of the handoff's « Étiquettes » table.
 *
 * A label is free text plus one of these; the pair itself (ground and ink, light and dark) lives in
 * app.css under `.cm-wall-tag--<value>`.
 */
enum WallLabelTone: string
{
    case Blue = 'blue';
    case Sand = 'sand';
    case Green = 'green';
    case Mauve = 'mauve';
    case Coral = 'coral';
    case Grey = 'grey';

    public function labelKey(): string
    {
        return match ($this) {
            self::Blue => 'wallToneBlueLabel',
            self::Sand => 'wallToneSandLabel',
            self::Green => 'wallToneGreenLabel',
            self::Mauve => 'wallToneMauveLabel',
            self::Coral => 'wallToneCoralLabel',
            self::Grey => 'wallToneGreyLabel',
        };
    }
}
