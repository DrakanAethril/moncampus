<?php

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSeancePlacement;
use App\Entity\ProgressionSequence;
use App\Repository\LessonSessionRepository;

/**
 * The 10 automatic-placement rules of design/design_handoff_progression/README.md §4, in one
 * place. Every screen of the module that moves something (create a progression, reorder its
 * séquences, drop one, reassociate) ends up calling replan() - the rules are never re-implemented
 * per controller action.
 *
 * The service only ever writes to the progression's own tables. It reads the timetable and,
 * on validate(), writes back a créneau's title/topic - it never creates, moves or deletes a
 * LessonSession, which stays staff-owned (this is why the design's "créer un créneau hors emploi
 * du temps" is deliberately out of this pass).
 */
class ProgressionPlacementService
{
    // §4.1 - a séance still fits a créneau if it overruns it by no more than 45 min. Expressed in
    // hours because every duration in this app is a decimal hour count (LessonSession::$length,
    // SeanceInstance::$duree), never minutes.
    public const float OVERRUN_TOLERANCE_HOURS = 0.75;

    public function __construct(private readonly LessonSessionRepository $lessonSessionRepository)
    {
    }

    /**
     * Re-runs the whole progression: walks its séquences in $position order and lays their
     * séances onto the topic's créneaux, chaining from one séquence to the next. Safe to call
     * repeatedly - it clears the placements it owns first, so it converges rather than piling up.
     *
     * Confirmed placements are preserved: once a teacher has hit "Valider le placement", replanning
     * a later séquence must not silently move a lesson they already committed to (§4.8 only
     * promises that reordering replans "les séquences suivantes", not that it rewrites history).
     */
    public function replan(Progression $progression): void
    {
        $topic = $progression->getTopic();
        if (null === $topic) {
            return;
        }

        $slots = $this->lessonSessionRepository->findOrderedForTopic($topic);
        $lockedSlotIds = $this->collectConfirmedSlotIds($progression);

        $sequences = $this->orderedSequences($progression);
        $cursor = 0;

        foreach ($sequences as $index => $sequence) {
            $sequence->setTruncatedByNext(false);

            // §4.5 - "Placer dans l'EDT" unchecked keeps the séquence in the progression without
            // ever touching a créneau.
            if (!$sequence->isPlaceInTimetable()) {
                $this->clearUnconfirmedPlacements($sequence);
                continue;
            }

            $start = $cursor;
            $forced = $sequence->getForcedStartDate();

            if (null !== $forced) {
                // §4.6 - a forced date wins over the natural chaining, and the créneaux skipped in
                // between simply stay free.
                $start = $this->firstSlotIndexOnOrAfter($slots, $forced);

                // §4.4 - it may land before the previous séquence had finished; that one is then
                // stopped at this date and flagged.
                if ($index > 0) {
                    $this->truncatePreviousAt($sequences[$index - 1], $forced, $lockedSlotIds);
                }
            }

            $cursor = $this->planSequence($sequence, $slots, $start, $lockedSlotIds);
        }
    }

    /**
     * Lays one séquence's séances onto $slots starting at index $from, and returns the index the
     * next séquence should start from.
     *
     * @param list<LessonSession> $slots
     * @param array<int, true>    $lockedSlotIds créneaux already committed elsewhere, never reused
     */
    private function planSequence(ProgressionSequence $sequence, array $slots, int $from, array &$lockedSlotIds): int
    {
        $cursor = $from;

        foreach ($sequence->getSeances() as $seance) {
            if ($seance->isRemoved()) {
                continue;
            }

            // A séance whose placement the teacher already validated is left exactly where it is,
            // and the cursor jumps past it so the following ones keep chaining forward.
            if ($this->hasConfirmedPlacement($seance)) {
                $cursor = max($cursor, $this->indexAfterLastPlacement($slots, $seance));
                continue;
            }

            $seance->clearPlacements();
            $seance->setTooShort(false);

            $cursor = $seance->isPerGroup()
                ? $this->placePerGroup($seance, $slots, $cursor, $lockedSlotIds)
                : $this->placeSingle($seance, $slots, $cursor, $lockedSlotIds);
        }

        return $cursor;
    }

    /**
     * The ordinary case: one séance onto the next free créneau, splitting it over consecutive
     * créneaux when it simply does not fit (§4.1 + the design's "2 h prévues → créneau d'1 h :
     * séance scindée sur 2 créneaux").
     *
     * @param list<LessonSession> $slots
     * @param array<int, true>    $lockedSlotIds
     */
    private function placeSingle(ProgressionSeance $seance, array $slots, int $cursor, array &$lockedSlotIds): int
    {
        $remaining = $seance->getPlannedDurationAsFloat();
        $index = $this->nextFreeSlotIndex($slots, $cursor, $lockedSlotIds);

        if (null === $index) {
            return $cursor;
        }

        $slot = $slots[$index];
        $slotHours = $this->slotHours($slot);

        // Fits (possibly overrunning by up to 45 min): one créneau, done. §4.3 - being *shorter*
        // than the créneau never blocks the placement, it only raises the flag.
        if ($remaining <= $slotHours + self::OVERRUN_TOLERANCE_HOURS) {
            $this->attach($seance, $slot, 0, $remaining > 0 ? $remaining : $slotHours);
            $lockedSlotIds[(int) $slot->getId()] = true;
            $seance->setTooShort($remaining > 0 && $remaining < $slotHours);

            return $index + 1;
        }

        // Too long: split over as many consecutive free créneaux as it takes.
        $partIndex = 0;
        while ($remaining > 0) {
            $next = $this->nextFreeSlotIndex($slots, $index, $lockedSlotIds);
            if (null === $next) {
                break;
            }

            $slot = $slots[$next];
            $slotHours = $this->slotHours($slot);
            $part = min($remaining, $slotHours);

            $this->attach($seance, $slot, $partIndex, $part);
            $lockedSlotIds[(int) $slot->getId()] = true;

            $remaining -= $part;
            ++$partIndex;
            $index = $next + 1;
        }

        return $index;
    }

    /**
     * §4.9 - a per-group séance is reproduced once per group, each group getting its own créneau.
     * The group notion is App\Entity\Option, the only sub-class split the timetable actually
     * carries (lesson_session_option), so this consumes the next créneaux that are each scoped to
     * a distinct Option. A créneau open to the whole class (no Option) can serve at most one
     * group, and each part keeps its own duration - that is what "ajustement de durée par groupe"
     * means.
     *
     * @param list<LessonSession> $slots
     * @param array<int, true>    $lockedSlotIds
     */
    private function placePerGroup(ProgressionSeance $seance, array $slots, int $cursor, array &$lockedSlotIds): int
    {
        $planned = $seance->getPlannedDurationAsFloat();
        $seen = [];
        $partIndex = 0;
        $index = $cursor;

        while (true) {
            $next = $this->nextFreeSlotIndex($slots, $index, $lockedSlotIds);
            if (null === $next) {
                break;
            }

            $slot = $slots[$next];
            $option = $this->soleOption($slot);
            $key = null === $option ? '*' : (string) $option->getId();

            if (isset($seen[$key])) {
                break;
            }

            $slotHours = $this->slotHours($slot);
            if ($planned > $slotHours + self::OVERRUN_TOLERANCE_HOURS) {
                break;
            }

            $placement = $this->attach($seance, $slot, $partIndex, $planned > 0 ? $planned : $slotHours);
            $placement->setOption($option);
            $lockedSlotIds[(int) $slot->getId()] = true;

            $seen[$key] = true;
            ++$partIndex;
            $index = $next + 1;

            // A créneau with no Option cannot be told apart from the next one, so there is no
            // second group to serve - stop after it rather than duplicating blindly.
            if (null === $option) {
                break;
            }
        }

        return $index;
    }

    /**
     * §4.10 as decided with the product owner: validating freezes the association and names the
     * créneau (title + matière), but deliberately creates NO LessonLog. Filling the cahier de
     * texte stays a manual act - see design/validated/lesson-log-cahier-de-texte.md, "filling is
     * never automatic"; the existing one-click "pré-remplir" is still the only way in.
     */
    public function validate(ProgressionSequence $sequence): void
    {
        $topic = $sequence->getProgression()?->getTopic();

        foreach ($sequence->getActiveSeances() as $seance) {
            $placements = $seance->getActivePlacements();
            $partCount = \count($placements);

            foreach ($placements as $placement) {
                $session = $placement->getLessonSession();
                if (null === $session) {
                    continue;
                }

                $placement->setConfirmed(true);
                $placement->captureSnapshot();
                $session->setTitle($this->sessionTitleFor($seance, $placement->getPartIndex(), $partCount));

                if (null !== $topic) {
                    $session->setTopic($topic);
                }
            }
        }
    }

    /**
     * "Réassocier automatiquement" on screen 2a: drops the drifted placements of this séquence
     * (§4.7's "à réassocier" state) and lets replan() lay them out again from scratch. Confirmed
     * placements that have NOT drifted are untouched.
     */
    public function reassociate(ProgressionSequence $sequence): void
    {
        foreach ($sequence->getSeances() as $seance) {
            if (!$seance->needsReassociation()) {
                continue;
            }

            $seance->clearPlacements();
        }

        $progression = $sequence->getProgression();
        if (null !== $progression) {
            $this->replan($progression);
        }
    }

    /**
     * The 2b picker's manual association: replaces a séance's placements with the picked créneaux.
     * $mode is 'duplicate' (same séance on each créneau, the per-group case) or 'split' (the
     * content spread over them in date order). $duration is the per-part duty in hours, null
     * meaning "= créneau".
     *
     * @param list<LessonSession> $sessions
     */
    public function associate(ProgressionSeance $seance, array $sessions, string $mode, ?float $duration): void
    {
        usort($sessions, $this->chronologically(...));

        $seance->clearPlacements();
        $seance->setPerGroup('duplicate' === $mode && \count($sessions) > 1);
        $seance->setTooShort(false);

        $planned = $seance->getPlannedDurationAsFloat();
        $remaining = $planned;

        foreach ($sessions as $partIndex => $session) {
            $slotHours = $this->slotHours($session);

            $part = match (true) {
                null !== $duration => $duration,
                'split' === $mode => min(max($remaining, 0.0), $slotHours),
                default => $planned > 0 ? $planned : $slotHours,
            };

            $placement = $this->attach($seance, $session, $partIndex, $part);

            if ('duplicate' === $mode && \count($sessions) > 1) {
                $placement->setOption($this->soleOption($session));
            } else {
                $remaining -= $part;
            }
        }

        // §4.3 stays true whichever way the teacher got here.
        if (1 === \count($sessions) && $planned > 0) {
            $seance->setTooShort($planned < $this->slotHours($sessions[0]));
        }
    }

    /**
     * Screen 2a's "Ou : ramener la séance à 1 h (durée du créneau)" and "Ajuster la séance à X h
     * pour ce groupe" - the séance's planned duration is brought down to what its créneau
     * actually offers, which by construction un-splits it.
     */
    public function fitToSlot(ProgressionSeance $seance, LessonSession $session): void
    {
        $hours = $this->slotHours($session);
        $seance->setPlannedDuration(number_format($hours, 2, '.', ''));
        $this->associate($seance, [$session], 'split', $hours);
    }

    /** @return list<ProgressionSequence> */
    private function orderedSequences(Progression $progression): array
    {
        $sequences = $progression->getSequences()->toArray();
        usort($sequences, static fn (ProgressionSequence $a, ProgressionSequence $b): int => $a->getPosition() <=> $b->getPosition());

        return array_values($sequences);
    }

    /**
     * §4.4 - everything the previous séquence had placed on or after $date is released, and the
     * séquence is flagged so the progression view can say so. The freed créneaux drop out of
     * $lockedSlotIds too, which is the whole point: they are what the forcing séquence is about
     * to sit on.
     *
     * @param array<int, true> $lockedSlotIds
     */
    private function truncatePreviousAt(ProgressionSequence $previous, \DateTimeImmutable $date, array &$lockedSlotIds): void
    {
        $cut = false;

        foreach ($previous->getSeances() as $seance) {
            foreach ($seance->getPlacements()->toArray() as $placement) {
                if ($placement->isConfirmed()) {
                    continue;
                }

                $session = $placement->getLessonSession();
                $day = $session?->getDay();
                if (null !== $day && $day >= $date) {
                    $seance->getPlacements()->removeElement($placement);
                    unset($lockedSlotIds[(int) $session?->getId()]);
                    $cut = true;
                }
            }
        }

        if ($cut) {
            $previous->setTruncatedByNext(true);
        }
    }

    private function clearUnconfirmedPlacements(ProgressionSequence $sequence): void
    {
        foreach ($sequence->getSeances() as $seance) {
            if ($this->hasConfirmedPlacement($seance)) {
                continue;
            }

            $seance->clearPlacements();
        }
    }

    /** @return array<int, true> */
    private function collectConfirmedSlotIds(Progression $progression): array
    {
        $ids = [];

        foreach ($progression->getSequences() as $sequence) {
            foreach ($sequence->getSeances() as $seance) {
                if ($seance->isRemoved()) {
                    continue;
                }
                foreach ($seance->getPlacements() as $placement) {
                    $sessionId = $placement->getLessonSession()?->getId();
                    if ($placement->isConfirmed() && null !== $sessionId) {
                        $ids[$sessionId] = true;
                    }
                }
            }
        }

        return $ids;
    }

    private function hasConfirmedPlacement(ProgressionSeance $seance): bool
    {
        foreach ($seance->getPlacements() as $placement) {
            if ($placement->isConfirmed()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<LessonSession> $slots */
    private function indexAfterLastPlacement(array $slots, ProgressionSeance $seance): int
    {
        $last = -1;

        foreach ($seance->getActivePlacements() as $placement) {
            $sessionId = $placement->getLessonSession()?->getId();
            foreach ($slots as $index => $slot) {
                if ($slot->getId() === $sessionId && $index > $last) {
                    $last = $index;
                }
            }
        }

        return $last + 1;
    }

    /**
     * §4.2 - in automatic mode a créneau carries at most one séance, so the walk skips anything
     * already taken (stacking several on one créneau stays possible, but only by hand via 2b).
     *
     * @param list<LessonSession> $slots
     * @param array<int, true>    $lockedSlotIds
     */
    private function nextFreeSlotIndex(array $slots, int $from, array $lockedSlotIds): ?int
    {
        for ($index = max($from, 0); $index < \count($slots); ++$index) {
            if (!isset($lockedSlotIds[(int) $slots[$index]->getId()])) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<LessonSession> $slots */
    private function firstSlotIndexOnOrAfter(array $slots, \DateTimeImmutable $date): int
    {
        foreach ($slots as $index => $slot) {
            $day = $slot->getDay();
            if (null !== $day && $day >= $date) {
                return $index;
            }
        }

        return \count($slots);
    }

    private function attach(ProgressionSeance $seance, LessonSession $session, int $partIndex, float $duration): ProgressionSeancePlacement
    {
        $placement = new ProgressionSeancePlacement($seance, $session);
        $placement->setPartIndex($partIndex);
        $placement->setDuration(number_format($duration, 2, '.', ''));

        return $placement;
    }

    // A créneau's usable length. LessonSession::$length is the manually entered figure everything
    // financial reads, but it is not derived from the hours (see that field's docblock), so fall
    // back to the actual start→end span when it is missing or zero.
    private function slotHours(LessonSession $session): float
    {
        $length = (float) ($session->getLength() ?? '0');
        if ($length > 0) {
            return $length;
        }

        $start = $session->getStartHour();
        $end = $session->getEndHour();
        if (null === $start || null === $end) {
            return 0.0;
        }

        return max(0.0, ($end->getTimestamp() - $start->getTimestamp()) / 3600);
    }

    // A créneau reserved for exactly one Option is that Option's group session; one open to the
    // whole class (or shared by several Options) has no single group to name.
    private function soleOption(LessonSession $session): ?Option
    {
        return 1 === $session->getOptions()->count() ? $session->getOptions()->first() : null;
    }

    private function sessionTitleFor(ProgressionSeance $seance, int $partIndex, int $partCount): string
    {
        return $partCount > 1
            ? sprintf('%s (%d/%d)', $seance->getTitle(), $partIndex + 1, $partCount)
            : $seance->getTitle();
    }

    private function chronologically(LessonSession $a, LessonSession $b): int
    {
        return [$a->getDay()?->format('Y-m-d'), $a->getStartHour()?->format('H:i')]
            <=> [$b->getDay()?->format('Y-m-d'), $b->getStartHour()?->format('H:i')];
    }
}
