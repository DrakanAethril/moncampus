<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The seven colours a .cm-badge comes in (assets/styles/app.css, "Buttons + badges of the design
 * system"). A case is a tone, not a meaning: which state reads as gold is the enum's decision, the
 * palette is this one's. A value missing here has no CSS rule - which is why a string typed by hand
 * in a template is refused by the Cm:Badge component rather than rendered colourless.
 */
enum BadgeTone: string
{
    case Blue = 'blue';
    case Gold = 'gold';
    case Green = 'green';
    case Red = 'red';
    case Purple = 'purple';
    case Teal = 'teal';
    case Gray = 'gray';
}
