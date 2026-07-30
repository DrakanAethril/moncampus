<?php

namespace App\Twig;

use App\Util\DurationFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

// Exposes App\Util\DurationFormatter to Twig, so a duration held in minutes (SeanceTemplate/
// SeanceInstance::$duree and everything the progression derives from them) is never printed as a
// raw number next to a hand-written " h".
class DurationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('duration_minutes', DurationFormatter::minutes(...)),
        ];
    }
}
