<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\DurationFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

// Exposes App\Util\DurationFormatter to Twig, so a duration held in minutes (SeanceTemplate/
// SeanceInstance::$duree and everything the progression derives from them) is never printed as a
// raw number next to a hand-written " h" - and so a per-question time measured in milliseconds
// (App\Entity\QuizAttemptAnswer::$elapsedMs) reads as "4 s" on every screen that shows it.
class DurationExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('duration_minutes', DurationFormatter::minutes(...)),
            new TwigFilter('duration_ms', DurationFormatter::milliseconds(...)),
        ];
    }
}
