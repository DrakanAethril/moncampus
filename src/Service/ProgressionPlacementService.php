<?php

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSeancePlacement;
use App\Entity\ProgressionSequence;
use App\Repository\LessonSessionRepository;
use App\Repository\SeanceInstanceRepository;

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
    // §4.1 - a séance still fits a créneau if it overruns it by no more than 45 min.
    //
    // The whole service works in MINUTES, because that is the unit séance durations are authored
    // in (SeanceTemplate/SeanceInstance::$duree - "55" is a 55-minute séance). A créneau's
    // LessonSession::$length is decimal HOURS instead, so it is converted once in slotMinutes()
    // and never compared raw: doing so is what used to make a 55-minute séance eat 55 hours of a
    // class's year.
    public const int OVERRUN_TOLERANCE_MINUTES = 45;

    // §4.3's "séance trop courte pour son créneau" only counts as a real gap past 15% of the
    // créneau, either way. A 55-minute séance in a 1 h créneau is the establishment's ordinary
    // hour once the changeover is taken out, not a discrepancy worth warning a teacher about -
    // flagging it made the warning meaningless on the screens where it appears most.
    public const float DURATION_TOLERANCE_RATIO = 0.15;

    public function __construct(
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly SeanceInstanceRepository $seanceInstanceRepository,
    ) {
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
        $remaining = $seance->getPlannedMinutesOrZero();
        $index = $this->nextFreeSlotIndex($slots, $cursor, $lockedSlotIds);

        if (null === $index) {
            return $cursor;
        }

        $slot = $slots[$index];
        $slotMinutes = $this->slotMinutes($slot);

        // §4.9 - this créneau does not hold the whole class (it names at least one Option), so the
        // séance has to be reproduced for the other groups rather than taught to one half only.
        //
        // Without this, the following group créneaux were simply handed to the FOLLOWING séances of
        // the séquence: group A got séance 1, group B got séance 2, group A got séance 3... and the
        // two halves of the class drifted through the séquence out of step, each seeing half of it.
        //
        // Only when the séance actually fits the créneau: a longer one still belongs to the split
        // path below, which spreads it over consecutive créneaux.
        if ($this->isGroupSlot($slot) && $remaining <= $slotMinutes + self::OVERRUN_TOLERANCE_MINUTES) {
            return $this->placePerGroup($seance, $slots, $cursor, $lockedSlotIds);
        }

        // Fits (possibly overrunning by up to 45 min): one créneau, done. §4.3 - being *shorter*
        // than the créneau never blocks the placement, it only raises the flag.
        if ($remaining <= $slotMinutes + self::OVERRUN_TOLERANCE_MINUTES) {
            $committed = $remaining > 0 ? $remaining : $slotMinutes;
            $this->attach($seance, $slot, 0, $committed);
            $lockedSlotIds[(int) $slot->getId()] = true;
            $seance->setTooShort($this->isShorterThanSlot($committed, $slotMinutes));

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
            $slotMinutes = $this->slotMinutes($slot);
            $part = min($remaining, $slotMinutes);

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
        $planned = $seance->getPlannedMinutesOrZero();
        $seen = [];
        $partIndex = 0;
        $index = $cursor;

        while (true) {
            $next = $this->nextFreeSlotIndex($slots, $index, $lockedSlotIds);
            if (null === $next) {
                break;
            }

            $slot = $slots[$next];
            $key = $this->groupKey($slot);

            if (isset($seen[$key])) {
                break;
            }

            // Once every group has had its own créneau, the class is whole again: the next
            // whole-class créneau belongs to the FOLLOWING séance, not to a third copy of this one.
            // Checked before attaching, unlike the '*' break at the bottom of the loop - that one
            // only covers the case where the run started on a whole-class créneau and there was
            // never a group to tell apart.
            if ('*' === $key && $partIndex > 0) {
                break;
            }

            $slotMinutes = $this->slotMinutes($slot);
            if ($planned > $slotMinutes + self::OVERRUN_TOLERANCE_MINUTES) {
                break;
            }

            $placement = $this->attach($seance, $slot, $partIndex, $planned > 0 ? $planned : $slotMinutes);
            // Only a créneau reserved for exactly ONE Option has a single group to name; one shared
            // by two Options is still a group créneau (it gets its own part above) but has no
            // single Option to display against that part.
            $placement->setOption($this->soleOption($slot));
            $lockedSlotIds[(int) $slot->getId()] = true;

            $seen[$key] = true;
            ++$partIndex;
            $index = $next + 1;

            // Reached only when the run STARTED on a whole-class créneau (the guard above catches
            // every other case): there is no group to tell it apart from the next one, so it serves
            // as the séance's single créneau rather than being duplicated blindly.
            if ('*' === $key) {
                break;
            }
        }

        // Kept honest rather than trusted from the caller: a séance that found only one créneau is
        // not "par groupe ×1", and one the timetable has since made whole-class stops claiming to
        // be split by group.
        $seance->setPerGroup($partIndex > 1);

        return $index;
    }

    /**
     * §4.10 as decided with the product owner: validating freezes the association and names the
     * créneau (title + matière), but deliberately creates NO LessonLog. Filling the cahier de
     * texte stays a manual act - see design/validated/lesson-log-cahier-de-texte.md, "filling is
     * never automatic"; the one-click "pré-remplir" is still the only way in.
     *
     * It is also what keeps that button reachable: "pré-remplir" finds a séance's frozen content
     * through SeanceInstance::$lessonSession, which used to be written by the (now removed)
     * program-side "planifier une séance" screen. See linkSeanceInstance() for the one case the
     * unique OneToOne can express.
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

            $this->linkSeanceInstance($seance, $placements);
        }
    }

    /**
     * Reconnects the séance's library instance to its créneau, which is what marks it "programmée"
     * on the Program-side séquences list.
     *
     * SeanceInstance::$lessonSession is a unique OneToOne, so it can name only ONE créneau. A
     * séance duplicated per group or split over two créneaux is therefore linked to its FIRST
     * placement rather than left unlinked: it IS scheduled - once per group - and reporting it as
     * unscheduled because it happens to occupy more than one créneau was simply wrong.
     *
     * The other créneaux are not lost for the lesson log: App\Service\SeanceContentResolver reaches
     * them through the progression's placements, so "pré-remplir" works on all of them.
     *
     * Any stale link on the same créneau is cleared first - two SeanceInstances pointing at one
     * LessonSession would violate the unique constraint.
     *
     * @param list<ProgressionSeancePlacement> $placements
     */
    private function linkSeanceInstance(ProgressionSeance $seance, array $placements): void
    {
        $instance = $seance->getSeanceInstance();
        $session = ($placements[0] ?? null)?->getLessonSession();

        if (null === $instance || null === $session) {
            return;
        }

        $occupant = $this->seanceInstanceRepository->findOneByLessonSession($session);
        if (null !== $occupant && $occupant !== $instance) {
            $occupant->setLessonSession(null);
        }

        $instance->setLessonSession($session);
    }

    /**
     * The exact undo of validate(), for a séquence about to leave the progression - whether it is
     * being dropped from the progression screen or its whole instantiation is being deleted.
     *
     * The placements themselves go with the rows, so what needs an explicit undo is everything
     * validate() wrote OUTSIDE them: the créneau's title, and the SeanceInstance↔créneau link that
     * marks the séance "programmée" on the Program-side list. Left behind, the timetable kept
     * advertising a séance nobody plans any more and the list kept counting it as scheduled.
     *
     * The title is only cleared when it still IS the one validate() wrote: a staff member who has
     * since renamed the créneau by hand has said something this module has no business overwriting.
     * The créneau's matière is never touched - it is a timetable fact, true whether or not this
     * séquence planned it - and the créneau itself is never deleted, it is staff-owned.
     */
    public function releaseSequence(ProgressionSequence $sequence): void
    {
        foreach ($sequence->getSeances() as $seance) {
            $placements = $seance->getActivePlacements();
            $partCount = \count($placements);

            foreach ($placements as $placement) {
                $session = $placement->getLessonSession();
                if (null === $session) {
                    continue;
                }

                if ($session->getTitle() === $this->sessionTitleFor($seance, $placement->getPartIndex(), $partCount)) {
                    $session->setTitle(null);
                }
            }

            $seance->getSeanceInstance()?->setLessonSession(null);
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
     * content spread over them in date order). $minutes is the per-part duty, null meaning
     * "= créneau".
     *
     * @param list<LessonSession> $sessions
     */
    public function associate(ProgressionSeance $seance, array $sessions, string $mode, ?int $minutes): void
    {
        usort($sessions, $this->chronologically(...));

        $seance->clearPlacements();
        $seance->setPerGroup('duplicate' === $mode && \count($sessions) > 1);
        $seance->setTooShort(false);

        $remaining = $seance->getPlannedMinutesOrZero();
        $lastPart = 0;
        $lastSlotMinutes = 0;

        foreach ($sessions as $partIndex => $session) {
            $slotMinutes = $this->slotMinutes($session);

            $part = match (true) {
                // An explicit duty from the picker's pills wins over everything.
                null !== $minutes => $minutes,
                // Splitting spreads the séquence's own content over the créneaux in date order.
                'split' === $mode => min(max($remaining, 0), $slotMinutes),
                // "= créneau" - the créneau's own length, taken literally. It used to fall back to
                // the séance's planned duration instead, so answering "= créneau" for a 55-min
                // séance on a 1 h créneau committed 55 min and then reported the séance as shorter
                // than its créneau: the teacher's own answer came back as a warning.
                default => $slotMinutes,
            };

            $placement = $this->attach($seance, $session, $partIndex, $part);
            $lastPart = $part;
            $lastSlotMinutes = $slotMinutes;

            if ('duplicate' === $mode && \count($sessions) > 1) {
                $placement->setOption($this->soleOption($session));
            } else {
                $remaining -= $part;
            }
        }

        // §4.3 stays true whichever way the teacher got here - but measured on the duration
        // actually COMMITTED to the créneau, not on the séquence's theoretical one. Picking a duty
        // ("1 h") or "= créneau" is the teacher stating what this class really gets, and it has to
        // be able to clear the flag; comparing against the séquence's planned duration meant it
        // never could.
        if (1 === \count($sessions)) {
            $seance->setTooShort($this->isShorterThanSlot($lastPart, $lastSlotMinutes));
        }
    }

    // A séance only counts as short of its créneau past DURATION_TOLERANCE_RATIO of that créneau.
    private function isShorterThanSlot(int $committedMinutes, int $slotMinutes): bool
    {
        if ($slotMinutes <= 0 || $committedMinutes <= 0) {
            return false;
        }

        return $slotMinutes - $committedMinutes > $slotMinutes * self::DURATION_TOLERANCE_RATIO;
    }

    /**
     * Screen 2a's "Ou : ramener la séance à 1 h (durée du créneau)" and "Ajuster la séance pour ce
     * groupe" - the séance's planned duration is brought down to what its créneau actually offers,
     * which by construction un-splits it.
     */
    public function fitToSlot(ProgressionSeance $seance, LessonSession $session): void
    {
        $minutes = $this->slotMinutes($session);
        $seance->setPlannedMinutes($minutes);
        $this->associate($seance, [$session], 'split', $minutes);
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

    private function attach(ProgressionSeance $seance, LessonSession $session, int $partIndex, int $minutes): ProgressionSeancePlacement
    {
        $placement = new ProgressionSeancePlacement($seance, $session);
        $placement->setPartIndex($partIndex);
        $placement->setDurationMinutes($minutes);

        return $placement;
    }

    // A créneau's usable length, in MINUTES. LessonSession::$length is the manually entered figure
    // everything financial reads and it is a decimal HOUR count, hence the ×60; it is not derived
    // from the start/end hours (see that field's docblock), so fall back to the actual start→end
    // span when it is missing or zero.
    private function slotMinutes(LessonSession $session): int
    {
        $length = (float) ($session->getLength() ?? '0');
        if ($length > 0) {
            return (int) round(60 * $length);
        }

        $start = $session->getStartHour();
        $end = $session->getEndHour();
        if (null === $start || null === $end) {
            return 0;
        }

        return max(0, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60));
    }

    // A créneau reserved for exactly one Option is that Option's group session; one open to the
    // whole class (or shared by several Options) has no single group to name.
    private function soleOption(LessonSession $session): ?Option
    {
        return 1 === $session->getOptions()->count() ? $session->getOptions()->first() : null;
    }

    /**
     * "The class is not complete on this créneau": it is reserved for at least one Option.
     *
     * The Option always comes from the CRÉNEAU (LessonSession, via lesson_session_option) - a
     * séance carries no Option of its own and never has: the same séance is what gets taught to
     * each group, and it is the timetable that says which groups exist and when they meet.
     */
    private function isGroupSlot(LessonSession $session): bool
    {
        return $session->getOptions()->count() > 0;
    }

    /**
     * What makes two créneaux "the same group" for §4.9: the set of Options they are reserved for,
     * order-independent, or '*' for the whole class.
     *
     * A set rather than a single Option, because a créneau shared by two of three Options is still
     * a partial class - the remaining group has to get the séance too, and hasn't yet.
     */
    private function groupKey(LessonSession $session): string
    {
        $ids = array_map(
            static fn (Option $option): int => (int) $option->getId(),
            $session->getOptions()->toArray(),
        );
        sort($ids);

        return [] === $ids ? '*' : implode('-', $ids);
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
