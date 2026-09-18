<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\VideoWatchEventType;

/**
 * One report from a video player, read and bounded - what VideoWatchTracker records.
 *
 * Both players send the same body, and the mobile app installed before the detail existed sends only
 * `percent`: every other key is optional and an absent one reads as « nothing to add ».
 *
 * ```json
 * {"percent": 42, "watchedSeconds": 5, "events": [{"type": "skip", "from": 130, "to": 405}, {"type": "focus_loss", "at": 212}]}
 * ```
 *
 * The bounds are the server's net, not a rule: the players report every few seconds, so a report
 * claiming more than MAX_WATCHED_SECONDS of playing, or more than MAX_EVENTS events, did not come
 * from one. Nothing here can raise the percentage - the detail describes the watching, it never
 * completes it.
 */
final readonly class VideoWatchReport
{
    /** Two minutes: the players flush every five seconds of playing, and on every pause. */
    public const int MAX_WATCHED_SECONDS = 120;

    public const int MAX_EVENTS = 20;

    /** The position ceiling when the file's duration is unknown (read by the browser, may be 0). */
    private const int MAX_POSITION_SECONDS = 86_400;

    /**
     * @param list<array{type: VideoWatchEventType, position: int, target: ?int}> $events
     */
    public function __construct(
        public int $percent,
        public int $watchedSeconds = 0,
        public array $events = [],
    ) {
    }

    public static function fromPayload(JsonRequestPayload $payload, int $durationSeconds): self
    {
        $ceiling = $durationSeconds > 0 ? $durationSeconds : self::MAX_POSITION_SECONDS;
        $clamp = static fn (float $seconds): int => (int) round(max(0.0, min((float) $ceiling, $seconds)));

        $events = [];
        foreach (\array_slice($payload->objects('events'), 0, self::MAX_EVENTS) as $entry) {
            $type = VideoWatchEventType::tryFrom($entry->string('type'));

            if (VideoWatchEventType::Skip === $type) {
                $from = $entry->float('from');
                $to = $entry->float('to');

                // A skip goes forward, or it is not one - a rewind misses nothing.
                if (null === $from || null === $to || $clamp($to) <= $clamp($from)) {
                    continue;
                }

                $events[] = ['type' => $type, 'position' => $clamp($from), 'target' => $clamp($to)];
            } elseif (VideoWatchEventType::FocusLoss === $type) {
                $events[] = ['type' => $type, 'position' => $clamp($entry->float('at') ?? 0.0), 'target' => null];
            }
        }

        return new self(
            $payload->int('percent', 0) ?? 0,
            max(0, min(self::MAX_WATCHED_SECONDS, (int) round($payload->float('watchedSeconds', 0.0) ?? 0.0))),
            $events,
        );
    }
}
