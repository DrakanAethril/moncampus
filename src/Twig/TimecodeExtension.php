<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\Timecode;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

// Exposes App\Util\Timecode to Twig, so a position inside a video read off an import payload - which
// carries plain seconds, not entities - is printed the same way the timeline and the markers print
// theirs. Same reasoning as DurationExtension one file over: a raw number of seconds beside a
// hand-written " s" is how two screens end up disagreeing.
class TimecodeExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('video_timecode', Timecode::format(...)),
        ];
    }
}
