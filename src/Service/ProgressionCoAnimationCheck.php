<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;

/**
 * « Groupe non couvert : G2 » - the one gap co-animation leaves in the model as it stands.
 *
 * Everything else co-animation needs was already there: a séance duplicates once per group, each
 * placement names its Option, and the créneaux name their teacher and their room. What nothing
 * answered is the asymmetric case - teacher A lays the séance on their own group's créneau and no
 * screen says the other group was forgotten.
 *
 * So this is a CHECK and not a field, and the distinction is the whole reason it exists here rather
 * than as a column: it is derived from placements that change constantly, and a stored flag would
 * be wrong the first time a créneau moved. That is the same reasoning
 * ProgressionSeancePlacement already applies to its snapshot hours - it compares them rather than
 * trusting a boolean.
 *
 * Scoped to CO-ANIMATED progressions, deliberately. A solo teacher splitting their class can leave
 * a group out too, but they are the only person planning it and the placement screen already shows
 * them every créneau; raising the chip there would be advice, not a finding. Under co-animation the
 * other half of the plan belongs to somebody else, which is what makes the omission invisible.
 */
class ProgressionCoAnimationCheck
{
    public function __construct(private readonly ProgressionSlotPool $slotPool)
    {
    }

    /**
     * The groups this séance was not delivered to, by short name - what the 2a/2b chip prints.
     *
     * @return list<string>
     */
    public function uncoveredGroups(ProgressionSeance $seance): array
    {
        $sequence = $seance->getProgressionSequence();
        if (null === $sequence || true !== $sequence->getProgression()?->isCoAnimated()) {
            return [];
        }

        return self::uncovered($this->offeredGroups($sequence), $this->coveredGroups($seance));
    }

    /**
     * The same answer for every séance of one séquence, computed off a single créneau read - what
     * screen 2a needs, since it lists them all.
     *
     * @return array<int, list<string>> keyed by séance id, absent when nothing is missing
     */
    public function uncoveredGroupsBySequence(ProgressionSequence $sequence): array
    {
        if (true !== $sequence->getProgression()?->isCoAnimated()) {
            return [];
        }

        $offered = $this->offeredGroups($sequence);

        $rows = [];
        foreach ($sequence->getActiveSeances() as $seance) {
            $missing = self::uncovered($offered, $this->coveredGroups($seance));
            if ([] !== $missing) {
                $rows[(int) $seance->getId()] = $missing;
            }
        }

        return $rows;
    }

    /**
     * The rule itself: which of the offered groups no placement delivered to.
     *
     * Two things it deliberately stays silent about, both of which would otherwise turn a normal
     * screen into a wall of orange:
     *
     *  - a séance on NO créneau at all - it is already « Non placée », with its own status chip,
     *    and naming both groups on top of that says the same thing twice;
     *  - a séance delivered to the whole class - a créneau naming no Option holds everybody, so
     *    every group received it. That is why the covered side carries nulls rather than keys.
     *
     * Keyed on the Option's id and never on its label: two Options may share a short name, and
     * covering one must not silence the other. The labels are only what comes back out.
     *
     * The keys are typed array-key rather than string although both sides build them with a (string)
     * cast: PHP normalises a numeric-string key back to an int the moment it enters an array, so an
     * Option id lands as 5 and never as '5'. Promising string here would be a docblock that no call
     * can satisfy - which is exactly what it was, and what the test caught. The comparison is
     * unaffected: array_flip() normalises the covered side the same way.
     *
     * @param array<array-key, string> $offered group key => short name, from the matière's créneaux
     * @param list<string|null>        $covered one entry per placement; null is a whole-class delivery
     *
     * @return list<string>
     */
    public static function uncovered(array $offered, array $covered): array
    {
        if ([] === $covered || \in_array(null, $covered, true)) {
            return [];
        }

        $seen = array_flip(array_filter($covered, static fn (?string $key): bool => null !== $key));

        $missing = [];
        foreach ($offered as $key => $label) {
            if (!isset($seen[$key])) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * The groups the class's créneaux of this séquence actually offer.
     *
     * Read off ProgressionSlotPool::forSequence(), so the séquence's own "Créneaux utilisés" apply:
     * a cycle de TP restricted to group créneaux does not get asked about a whole-class one.
     *
     * @return array<array-key, string> group key => short name - see uncovered() on why the key
     *                                   type is not string despite the cast
     */
    private function offeredGroups(ProgressionSequence $sequence): array
    {
        $groups = [];

        foreach ($this->slotPool->forSequence($sequence) as $slot) {
            foreach ($slot->getOptions() as $option) {
                $groups[(string) $option->getId()] = $option->getShortName();
            }
        }

        return $groups;
    }

    /**
     * @return list<string|null> one entry per active placement; null is a whole-class delivery
     */
    private function coveredGroups(ProgressionSeance $seance): array
    {
        $covered = [];

        foreach ($seance->getActivePlacements() as $placement) {
            $option = $placement->getOption();

            // A placement with no Option of its own is only a whole-class delivery when its
            // créneau holds the whole class. The picker sets the Option on duplicate placements
            // only, so a séance split over two group créneaux for one group carries none - reading
            // that as "everybody got it" would silence the very case this check exists for.
            $covered[] = null !== $option
                ? (string) $option->getId()
                : $this->soleGroupOf($placement->getLessonSession());
        }

        return $covered;
    }

    /**
     * The group a créneau is reserved for, or null when it holds the whole class.
     *
     * A créneau naming several Options is not a half-group in the sense this check uses - it is
     * offered to all of them at once, so nobody is left out.
     */
    private function soleGroupOf(?LessonSession $session): ?string
    {
        if (null === $session) {
            return null;
        }

        $options = $session->getOptions();

        return 1 === $options->count() ? (string) $options->first()->getId() : null;
    }
}
