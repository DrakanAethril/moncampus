<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LessonSession;

/**
 * What a single run of ProgressionPlacementService::replan() has already spent: the créneaux taken,
 * and the weeks those créneaux fell in.
 *
 * The two are tracked differently on purpose. A créneau is taken or it is not, so it is a set. A
 * week is held by however many créneaux landed in it, so it is a COUNT - §4.4's "a forced start
 * date truncates the previous séquence" gives back créneaux mid-run, and a week must only be freed
 * once the last séance sitting in it has gone. Flags could not express that, and the week would
 * have stayed blocked for the séquence that had just cleared it.
 */
class ProgressionSlotLedger
{
    /** @var array<int, true> */
    private array $slotIds = [];

    /** @var array<string, int> ISO year-week => number of créneaux held in it */
    private array $weekCounts = [];

    /**
     * @param array<int, true> $lockedSlotIds créneaux taken outside this run - another progression's
     *                                        committed lessons. They lock the créneau but not its
     *                                        week: "une séance par semaine" is this séquence's own
     *                                        rhythm, and a colleague's lesson is not one of its
     *                                        séances.
     */
    public function __construct(array $lockedSlotIds = [])
    {
        $this->slotIds = $lockedSlotIds;
    }

    public function isSlotTaken(LessonSession $slot): bool
    {
        return isset($this->slotIds[(int) $slot->getId()]);
    }

    public function takeSlot(LessonSession $slot): void
    {
        $this->slotIds[(int) $slot->getId()] = true;

        $week = self::weekOf($slot);
        if (null !== $week) {
            $this->weekCounts[$week] = ($this->weekCounts[$week] ?? 0) + 1;
        }
    }

    public function releaseSlot(LessonSession $slot): void
    {
        unset($this->slotIds[(int) $slot->getId()]);

        $week = self::weekOf($slot);
        if (null === $week || !isset($this->weekCounts[$week])) {
            return;
        }

        --$this->weekCounts[$week];
        if ($this->weekCounts[$week] <= 0) {
            unset($this->weekCounts[$week]);
        }
    }

    /**
     * @param array<string, true> $exempt weeks this same séance has already claimed - its remaining
     *                                    parts (the other groups, or the second half of a split
     *                                    one) belong in them, and must not be pushed a week away
     */
    public function isWeekTaken(LessonSession $slot, array $exempt = []): bool
    {
        $week = self::weekOf($slot);

        return null !== $week && isset($this->weekCounts[$week]) && !isset($exempt[$week]);
    }

    /** The ISO year-week a créneau falls in - "2026-W36". Null for a créneau with no date yet. */
    public static function weekOf(LessonSession $slot): ?string
    {
        // 'o' is the ISO-8601 week-numbering year, not 'Y': the week straddling New Year belongs to
        // one of the two, and pairing 'Y' with 'W' would split it in half or merge two distinct
        // weeks - either way a January séance would land in the wrong bucket.
        return $slot->getDay()?->format('o-\WW');
    }
}
