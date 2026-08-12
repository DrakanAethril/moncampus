<?php

declare(strict_types=1);

namespace App\Service;

/** One segment of the retention map: how much of the class watched that stretch of video through. */
final readonly class VideoRetentionPoint
{
    public function __construct(
        public int $startSeconds,
        public int $endSeconds,
        public int $count,
        public int $sharePercent,
    ) {
    }

    /** "08:12" - how the map labels a moment of the video. */
    public function getFormattedStart(): string
    {
        return \sprintf('%d:%02d', intdiv($this->startSeconds, 60), $this->startSeconds % 60);
    }
}
