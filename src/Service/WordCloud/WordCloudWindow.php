<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

/**
 * The five values that decide whether a word cloud is taking words right now, and nothing else.
 *
 * A value object rather than the entity itself, so that WordCloudSchedule can be tested against a
 * clock and a handful of dates - the arithmetic is where the mistakes are, and none of them needs
 * a Program, a roster or a database.
 */
final readonly class WordCloudWindow
{
    public function __construct(
        public ?\DateTimeImmutable $opensAt,
        public ?\DateTimeImmutable $closesAt,
        /** « Ouverture manuelle »: the cloud waits for the teacher rather than for the clock. */
        public bool $manualOpening = false,
        /** Stamped when the teacher opens a manual cloud. */
        public ?\DateTimeImmutable $openedAt = null,
        /** Stamped by « Clore les soumissions », which ends the cloud whatever the window said. */
        public ?\DateTimeImmutable $closedAt = null,
    ) {
    }
}
