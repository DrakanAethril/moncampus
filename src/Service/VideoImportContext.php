<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\VideoResourceFile;
use App\Util\Timecode;

/**
 * "This import is being made from a video, and here is the video." Passed to
 * App\Service\QuizCsvImporter, whose behaviour it changes in exactly three ways: the `timecode`
 * column becomes required, every value in it is bounded by the running time, and the questions
 * carry their minute mark out to the preview.
 *
 * A separate object rather than a bare `?int $duration` argument because the import screen needs
 * the same two facts for something else: a language model cannot watch the video, so the prompt it
 * is given has the title and the duration written into it (conception_1_3_5.md). One object holds
 * what "from this video" means, and both readers take it.
 */
final class VideoImportContext
{
    public function __construct(
        public readonly int $durationSeconds,
        public readonly string $title = '',
    ) {
    }

    public static function forFile(VideoResourceFile $file): self
    {
        return new self($file->getDurationSeconds(), $file->getOriginalName());
    }

    /** "12:40" - what the prompt and the import screen both show. */
    public function formattedDuration(): string
    {
        return Timecode::format($this->durationSeconds);
    }

    /**
     * A duration of zero means the browser never managed to read one off the file (see
     * App\Controller\VideoResourceController::upload()). Bounding against it would refuse every
     * timecode, so nothing is bounded at all - which is the same answer the retention map gives to
     * the same missing number.
     */
    public function accepts(int $timecodeSeconds): bool
    {
        return 0 === $this->durationSeconds || $timecodeSeconds <= $this->durationSeconds;
    }
}
