<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A value the platform shows as a .cm-badge: a translation key for the text, a tone for the colour.
 * `<twig:Cm:Badge :of="loan.state"/>` reads both, so the pairing of a state with its colour is
 * written once, on the enum, and never again as a ternary in a template.
 *
 * Enums drawn in another family of pill (cm-audio-pill, cm-wc-tag, cm-step) do not implement it:
 * they are not badges.
 */
interface HasBadge
{
    public function labelKey(): string;

    public function badgeTone(): BadgeTone;
}
