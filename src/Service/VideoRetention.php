<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns what each student watched into the map the teacher reads: "passages réellement vus - part de
 * la classe, minute par minute", plus the moment the class dropped off.
 *
 * The whole reading rests on the single number the tracking stores per student and per file - the
 * furthest point they ever reached watching contiguously (App\Entity\VideoWatchProgress). That is
 * enough for a retention curve, and it is the honest one: it says how far the class got, not which
 * passages were replayed. A per-second heat map would need a second table and would measure
 * something the completion rule does not use.
 *
 * Nothing here is stored. Adding or removing a file changes the figures with nothing to recompute,
 * exactly as the audio statistics do.
 */
class VideoRetention
{
    /**
     * How many students must be lost at one boundary before it is called a drop-off. One student
     * leaving is a student, not a moment in the video, and a sentence printed on every video is a
     * sentence nobody reads.
     */
    private const int DROP_OFF_MINIMUM = 2;

    /**
     * @param list<int> $percents what each targeted student watched of the file, 0 for those who
     *                            never started - they are part of the class and the share is taken
     *                            over all of them
     */
    public function curve(array $percents, int $durationSeconds, int $segments = 24): VideoRetentionCurve
    {
        // A file whose duration was never measured cannot be laid on a timeline. No map rather than
        // a curve over a zero-second video.
        if ($durationSeconds <= 0 || $segments <= 0) {
            return new VideoRetentionCurve([]);
        }

        $watchedSeconds = array_map(
            static fn (int $percent): float => max(0, min(100, $percent)) / 100 * $durationSeconds,
            $percents,
        );

        // The tolerance absorbs the rounding of a percentage into seconds - at 100% the student must
        // be credited to the last segment, which is the only way a fully watched video reads as one.
        // Relative to the segment, never longer than a quarter of it: a flat half-second is longer
        // than a whole segment on a short video, and it then credited everybody with its opening -
        // which is what the four-second clip on the dev machine showed, drop-off at 0:00 included.
        $tolerance = min(0.5, $durationSeconds / $segments / 4);

        $points = [];
        for ($index = 0; $index < $segments; ++$index) {
            $start = (int) round($durationSeconds * $index / $segments);
            $end = (int) round($durationSeconds * ($index + 1) / $segments);

            // Watched THROUGH the segment, not merely into it: a student who stopped mid-minute did
            // not see that minute, and counting them would smooth over the drop-off itself. Watching
            // nothing at all is never watching a segment, whatever the tolerance.
            $count = \count(array_filter(
                $watchedSeconds,
                static fn (float $seconds): bool => $seconds > 0 && $seconds >= $durationSeconds * ($index + 1) / $segments - $tolerance,
            ));

            $points[] = new VideoRetentionPoint(
                $start,
                $end,
                $count,
                [] === $percents ? 0 : (int) round($count / \count($percents) * 100),
            );
        }

        return new VideoRetentionCurve($points, $this->dropOffOf($points));
    }

    /** @param list<VideoRetentionPoint> $points */
    private function dropOffOf(array $points): ?VideoRetentionDropOff
    {
        $worst = null;

        for ($index = 1; $index < \count($points); ++$index) {
            $lost = $points[$index - 1]->count - $points[$index]->count;

            if ($lost >= self::DROP_OFF_MINIMUM && (null === $worst || $lost > $worst->before - $worst->after)) {
                $worst = new VideoRetentionDropOff($points[$index]->startSeconds, $points[$index - 1]->count, $points[$index]->count);
            }
        }

        return $worst;
    }
}
