<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The moment most of the class stopped watching - the one sentence the retention map is read for.
 *
 * It is shown and nothing else: the decision taken for this chantier is that dropping off is not
 * notified. The teacher who looks sees it; nobody is alerted, that would belong to the pilotage
 * chantier rather than to the video one.
 */
final readonly class VideoRetentionDropOff
{
    public function __construct(
        public int $atSeconds,
        public int $before,
        public int $after,
    ) {
    }

    public function getFormattedAt(): string
    {
        return \sprintf('%d:%02d', intdiv($this->atSeconds, 60), $this->atSeconds % 60);
    }
}
