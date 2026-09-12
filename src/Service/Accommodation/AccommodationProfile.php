<?php

declare(strict_types=1);

namespace App\Service\Accommodation;

use App\Entity\Accommodation;

/**
 * What a person's accommodations add up to - the single object every consumer reads, so that no
 * screen ever loops over App\Entity\User::$accommodations itself.
 *
 * A student may hold several, so a criterion is never one row's answer: **for extra time the
 * largest percentage wins**, it is not a sum. Two accommodations granting a third more do not grant
 * two thirds, and an accommodation silent on time (null) says nothing rather than zero - the
 * difference matters the day a second criterion exists and some rows carry only that one.
 *
 * Immutable and built from rows, never from a User: the combination rule is what deserves a test,
 * and the test should be able to build two Accommodations rather than a whole account.
 */
final class AccommodationProfile
{
    private function __construct(
        /** The largest « ajout de temps » granted, as a percentage. 0.0 when none is. */
        public readonly float $quizExtraTimePercent,
    ) {
    }

    public static function none(): self
    {
        return new self(0.0);
    }

    /**
     * @param iterable<Accommodation> $accommodations already filtered to the ones that still apply -
     *                                                see AccommodationResolver, which is what drops
     *                                                the deactivated rows
     */
    public static function fromAccommodations(iterable $accommodations): self
    {
        $extraTime = 0.0;

        foreach ($accommodations as $accommodation) {
            $percent = $accommodation->getQuizExtraTimePercent();
            if (null !== $percent) {
                $extraTime = max($extraTime, (float) $percent);
            }
        }

        return new self($extraTime);
    }

    public function hasQuizExtraTime(): bool
    {
        return $this->quizExtraTimePercent > 0.0;
    }

    /** The per-question budget this person actually gets, from the one the quiz sets. */
    public function applyToSeconds(?int $seconds): ?int
    {
        return ExtraTime::apply($seconds, $this->quizExtraTimePercent);
    }

    /** The same for the whole-quiz budget, which the quiz states in minutes and the clock counts in seconds. */
    public function applyToMinutesAsSeconds(?int $minutes): ?int
    {
        return ExtraTime::apply(null === $minutes ? null : $minutes * 60, $this->quizExtraTimePercent);
    }

    /**
     * The value to freeze on a row that records what was granted (App\Entity\QuizAttempt::$extraTimePercent),
     * or null when there is nothing to freeze - null and 0 mean the same thing to a reader, and null
     * is the one that says "no accommodation was in play" rather than "one was, worth nothing".
     */
    public function quizExtraTimeForStorage(): ?string
    {
        return $this->hasQuizExtraTime() ? number_format($this->quizExtraTimePercent, 2, '.', '') : null;
    }

    /** « 33,33 % », for the one place that says so out loud. Null when there is nothing to say. */
    public function quizExtraTimeLabel(): ?string
    {
        return $this->hasQuizExtraTime() ? ExtraTime::percentLabel($this->quizExtraTimePercent) : null;
    }
}
