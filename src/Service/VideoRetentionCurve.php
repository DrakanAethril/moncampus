<?php

declare(strict_types=1);

namespace App\Service;

/** The retention map of one video: its segments, and where the class dropped off if it did. */
final readonly class VideoRetentionCurve
{
    /** @param list<VideoRetentionPoint> $points */
    public function __construct(
        public array $points,
        public ?VideoRetentionDropOff $dropOff = null,
    ) {
    }

    /** The tallest bar, which is what the map's heights are drawn as a share of. */
    public function getPeakCount(): int
    {
        $counts = array_map(static fn (VideoRetentionPoint $point): int => $point->count, $this->points);

        return [] === $counts ? 0 : max($counts);
    }
}
