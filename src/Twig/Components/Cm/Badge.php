<?php

declare(strict_types=1);

namespace App\Twig\Components\Cm;

use App\Enum\BadgeTone;
use App\Enum\HasBadge;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A .cm-badge. Two ways in:
 *
 *   <twig:Cm:Badge :of="loan.state"/>             - text and colour both come from the value
 *   <twig:Cm:Badge tone="gold">Brouillon</twig:Cm:Badge> - a badge that belongs to no enum
 *
 * With `of`, the content block, when given, replaces the text but keeps the value's colour. A tone
 * typed by hand is read through BadgeTone, so « secondary » or « yellow » fails on the screen that
 * wrote it instead of drawing a colourless pill.
 */
#[AsTwigComponent('Cm:Badge')]
final class Badge
{
    public BadgeTone $tone = BadgeTone::Gray;

    public ?string $labelKey = null;

    public function mount(?HasBadge $of = null, BadgeTone|string|null $tone = null): void
    {
        if (null !== $of) {
            $this->tone = $of->badgeTone();
            $this->labelKey = $of->labelKey();
        }

        if (null !== $tone) {
            $this->tone = $tone instanceof BadgeTone ? $tone : BadgeTone::from($tone);
        }
    }
}
