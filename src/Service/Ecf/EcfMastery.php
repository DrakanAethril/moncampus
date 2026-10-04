<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfVisa;
use App\Enum\EcfActivityState;
use App\Enum\EcfPart;
use App\Enum\EcfResult;

/**
 * Where an activity-type stands, and whether it is mastered (design/validated/ecf-booklet.md, R8).
 *
 * Nobody ticks the synthesis' « OUI / NON »: an activity is mastered when its last signed part - the
 * complementary page when that one is signed, the main sheet otherwise - says « satisfait ».
 */
class EcfMastery
{
    public function state(EcfBooklet $booklet, ?EcfActivity $activity): EcfActivityState
    {
        if (null === $activity) {
            return EcfActivityState::ToFill;
        }

        $decisive = $this->decisiveResult($booklet, $activity);
        if (null !== $decisive) {
            return EcfResult::Satisfied === $decisive ? EcfActivityState::Satisfied : EcfActivityState::NotSatisfied;
        }

        return $this->hasContent($activity) ? EcfActivityState::InProgress : EcfActivityState::ToFill;
    }

    /** True or false once a part is signed, null while nothing decides yet. */
    public function isMastered(EcfBooklet $booklet, ?EcfActivity $activity): ?bool
    {
        if (null === $activity) {
            return null;
        }

        $decisive = $this->decisiveResult($booklet, $activity);

        return null === $decisive ? null : EcfResult::Satisfied === $decisive;
    }

    /** The result of the last signed part, or null when no part of the activity is signed. */
    public function decisiveResult(EcfBooklet $booklet, EcfActivity $activity): ?EcfResult
    {
        if ($this->isSigned($booklet, $activity, EcfPart::Complementary)) {
            return $activity->getComplementaryResult();
        }

        if ($this->isSigned($booklet, $activity, EcfPart::Main)) {
            return $activity->getResult();
        }

        return null;
    }

    public function isSigned(EcfBooklet $booklet, ?EcfActivity $activity, EcfPart $part): bool
    {
        return [] !== self::visasOf($booklet, $activity, $part);
    }

    /** @return list<EcfVisa> ordered by slot */
    public static function visasOf(EcfBooklet $booklet, ?EcfActivity $activity, EcfPart $part): array
    {
        $visas = array_values(array_filter(
            $booklet->getVisas()->toArray(),
            static fn (EcfVisa $visa): bool => $visa->covers($activity, $part),
        ));
        usort($visas, static fn (EcfVisa $a, EcfVisa $b): int => $a->getSlot()->value <=> $b->getSlot()->value);

        return $visas;
    }

    private function hasContent(EcfActivity $activity): bool
    {
        foreach ($activity->getRows() as $row) {
            if (!$row->isBlank()) {
                return true;
            }
        }

        return null !== $activity->getResult()
            || null !== $activity->getComplementaryResult()
            || '' !== trim((string) $activity->getAttentionPoints())
            || '' !== trim((string) $activity->getReassessNote())
            || [] !== $activity->getReassessCompetences()
            || '' !== trim((string) $activity->getComplementaryObservations());
    }
}
