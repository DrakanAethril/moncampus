<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\LessonSession;

/**
 * Which créneaux of a matière a séquence may be laid onto, by how much of the class they hold.
 *
 * The notion is the timetable's own and nothing else's: a créneau reserved for at least one
 * App\Entity\Option is a group créneau, one naming none holds the whole class (see
 * ProgressionPlacementService::isGroupSlot(), which asks the same question for §4.9). A séance
 * carries no group of its own, so this is deliberately a property of the SÉQUENCE - "cette
 * séquence est un cycle de TP" is the thing a teacher actually knows.
 */
enum ProgressionSlotComposition: string
{
    case All = 'all';
    case GroupOnly = 'group';
    case WholeClassOnly = 'whole_class';

    public function labelKey(): string
    {
        return match ($this) {
            self::All => 'progressionSlotCompositionAllLabel',
            self::GroupOnly => 'progressionSlotCompositionGroupLabel',
            self::WholeClassOnly => 'progressionSlotCompositionWholeClassLabel',
        };
    }

    public function accepts(LessonSession $session): bool
    {
        $isGroupSlot = $session->getOptions()->count() > 0;

        return match ($this) {
            self::All => true,
            self::GroupOnly => $isGroupSlot,
            self::WholeClassOnly => !$isGroupSlot,
        };
    }
}
