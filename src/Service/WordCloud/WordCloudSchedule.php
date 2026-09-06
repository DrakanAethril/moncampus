<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Enum\WordCloudStatus;

/**
 * The only answer to « ce nuage prend-il des mots en ce moment ? ».
 *
 * It is read by the pilot screen, by the list, by the projection and - the one that matters - by
 * the submission endpoint, so that a closed cloud refuses a word **server-side** rather than merely
 * hiding its form. The status is derived at every call and never stored: a cloud whose closing time
 * passes while nobody is looking must be closed the next time somebody asks, without a cron.
 */
class WordCloudSchedule
{
    public function status(WordCloudWindow $window, \DateTimeImmutable $now): WordCloudStatus
    {
        // « Clore les soumissions » is a decision, and it outranks a window still running.
        if (null !== $window->closedAt) {
            return WordCloudStatus::Closed;
        }

        $start = $window->manualOpening ? $window->openedAt : $window->opensAt;

        // A manual cloud with nobody having opened it, or a scheduled one with no opening time at
        // all: nothing has started, so nothing is taking words.
        if (null === $start || $now < $start) {
            return WordCloudStatus::Scheduled;
        }

        if (null !== $window->closesAt && $now > $window->closesAt) {
            return WordCloudStatus::Closed;
        }

        return WordCloudStatus::Open;
    }

    public function isOpen(WordCloudWindow $window, \DateTimeImmutable $now): bool
    {
        return WordCloudStatus::Open === $this->status($window, $now);
    }

    /**
     * Seconds left before the cloud closes on its own, or null when it has no end - which is what a
     * manually opened cloud looks like until somebody closes it.
     */
    public function remainingSeconds(WordCloudWindow $window, \DateTimeImmutable $now): ?int
    {
        if (null === $window->closesAt) {
            return null;
        }

        return max(0, $window->closesAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Where « Prolonger 5 min » moves the closing time to, or null when there is no closing time to
     * move - the button is not a way of giving an open-ended cloud a deadline.
     *
     * An end already gone by is caught up to `$now` first, so pressing it on a cloud that has just
     * closed genuinely reopens it for five minutes instead of leaving it shut.
     */
    public function extendedClosingTime(WordCloudWindow $window, \DateTimeImmutable $now, int $minutes): ?\DateTimeImmutable
    {
        if (null === $window->closesAt) {
            return null;
        }

        $from = max($window->closesAt, $now);

        return $from->modify(\sprintf('+%d minutes', $minutes));
    }
}
