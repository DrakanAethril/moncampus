<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfVisa;
use App\Entity\User;
use App\Enum\EcfPart;
use App\Enum\EcfResult;
use App\Enum\EcfVisaSlot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives and takes back the visas of an ECF booklet (design/validated/ecf-booklet.md, R5 to R9).
 *
 *  - A visa is the signer's own: their name, and the server's clock as the « Signé numériquement
 *    le » date. The evaluation date is entered, and may not be in the future.
 *  - A part is signed complete (R7), and its first visa copies the activity's labels onto it (R6).
 *  - The slots fill in order - second evaluator after the first, representative after an
 *    evaluator - and the two evaluator lines are two different people.
 *  - The synthesis is signed once every activity is decided (R9); the representative's visa closes
 *    the booklet.
 *  - Taking visas back removes all of a part's visas at once, and is refused while something signed
 *    later depends on them.
 */
class EcfSigner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EcfMastery $mastery,
    ) {
    }

    /**
     * @param EcfActivityType|null  $type  the activity-type signed, null for the synthesis
     * @param list<EcfActivityType> $types every activity-type of the booklet, for the synthesis
     */
    public function sign(EcfBooklet $booklet, ?EcfActivityType $type, EcfPart $part, EcfVisaSlot $slot, User $signer, \DateTimeImmutable $evaluatedOn, \DateTimeImmutable $now, array $types = []): EcfVisa
    {
        if ($booklet->isClosed()) {
            throw new EcfRefusal('ecfRefusalClosedMessage');
        }
        if (!\in_array($slot, EcfVisaSlot::forPart($part), true)) {
            throw new EcfRefusal('ecfRefusalSlotMessage');
        }
        if ($evaluatedOn->format('Y-m-d') > $now->format('Y-m-d')) {
            throw new EcfRefusal('ecfRefusalFutureDateMessage');
        }

        $activity = null;
        if (EcfPart::Synthesis === $part) {
            $this->assertSynthesisReady($booklet, $types);
        } else {
            if (null === $type) {
                throw new \InvalidArgumentException('An activity part is signed for an activity-type.');
            }
            $activity = $booklet->activityFor($type->code) ?? throw new EcfRefusal('ecfRefusalIncompleteMessage');
            $this->assertComplete($booklet, $activity, $part);
        }

        $visas = EcfMastery::visasOf($booklet, $activity, $part);
        $taken = array_map(static fn (EcfVisa $visa): EcfVisaSlot => $visa->getSlot(), $visas);
        if (\in_array($slot, $taken, true)) {
            throw new EcfRefusal('ecfRefusalSlotTakenMessage');
        }
        // The two evaluator lines are two people. The organisation's representative may be one of
        // them: in a small structure the trainer often represents it too.
        if (EcfVisaSlot::Representative !== $slot) {
            foreach ($visas as $visa) {
                if ($visa->getSigner() === $signer && EcfVisaSlot::Representative !== $visa->getSlot()) {
                    throw new EcfRefusal('ecfRefusalAlreadySignedMessage');
                }
            }
        }
        if (EcfVisaSlot::Evaluator1 !== $slot && !\in_array(EcfVisaSlot::Evaluator1, $taken, true)) {
            throw new EcfRefusal('ecfRefusalSlotOrderMessage');
        }

        if (null !== $activity && !$activity->isFrozen()) {
            $activity->freeze($type->label, $type->competences);
        }

        $visa = new EcfVisa($booklet, $activity, $part, $slot, $signer, self::printedName($signer), $evaluatedOn, $now);
        if (EcfVisaSlot::Representative === $slot) {
            $booklet->setClosedAt($now);
        }

        $this->entityManager->persist($visa);
        $this->entityManager->flush();

        return $visa;
    }

    public function unsign(EcfBooklet $booklet, ?EcfActivity $activity, EcfPart $part): void
    {
        if (EcfPart::Synthesis !== $part) {
            if (null === $activity) {
                throw new \InvalidArgumentException('An activity part is unsigned for an activity.');
            }
            if ($this->mastery->isSigned($booklet, null, EcfPart::Synthesis)) {
                throw new EcfRefusal('ecfRefusalSynthesisSignedMessage');
            }
            if (EcfPart::Main === $part && $this->mastery->isSigned($booklet, $activity, EcfPart::Complementary)) {
                throw new EcfRefusal('ecfRefusalComplementarySignedMessage');
            }
        }

        $visas = EcfMastery::visasOf($booklet, $activity, $part);
        if ([] === $visas) {
            throw new EcfRefusal('ecfRefusalNothingToUnsignMessage');
        }
        foreach ($visas as $visa) {
            $booklet->removeVisa($visa);
            $this->entityManager->remove($visa);
        }

        if (EcfPart::Synthesis === $part) {
            $booklet->setClosedAt(null);
        } elseif (!$this->mastery->isSigned($booklet, $activity, EcfPart::Main) && !$this->mastery->isSigned($booklet, $activity, EcfPart::Complementary)) {
            $activity->unfreeze();
        }

        $this->entityManager->flush();
    }

    /**
     * What keeps a part from being signed, as translation keys - empty when it can be.
     *
     * @return list<string>
     */
    public function missing(EcfBooklet $booklet, ?EcfActivity $activity, EcfPart $part): array
    {
        if (null === $activity) {
            return ['ecfMissingRowsMessage', 'ecfMissingResultMessage'];
        }

        $rows = $activity->rowsOf($part);
        $missing = [];
        if ([] === $rows) {
            $missing[] = 'ecfMissingRowsMessage';
        }
        foreach ($rows as $row) {
            if ('' === trim($row->getDescription()) || null === $row->getEvaluatedOn() || [] === $row->getCompetences()) {
                $missing[] = 'ecfMissingRowFieldsMessage';

                break;
            }
        }

        $result = $activity->resultOf($part);
        if (null === $result) {
            $missing[] = 'ecfMissingResultMessage';
        } elseif (EcfPart::Main === $part && EcfResult::NotSatisfied === $result
            && null === $activity->getAttentionPoints() && null === $activity->getReassessNote() && [] === $activity->getReassessCompetences()) {
            $missing[] = 'ecfMissingNotSatisfiedMessage';
        }

        return $missing;
    }

    /**
     * Why the synthesis cannot be signed yet, as translation keys - empty when it can.
     *
     * @param list<EcfActivityType> $types
     *
     * @return list<string>
     */
    public function synthesisMissing(EcfBooklet $booklet, array $types): array
    {
        if ([] === $types) {
            return ['ecfMissingActivitiesMessage'];
        }

        foreach ($types as $type) {
            $activity = $booklet->activityFor($type->code);
            if (null === $activity || null === $this->mastery->decisiveResult($booklet, $activity)) {
                return ['ecfMissingActivitiesMessage'];
            }
        }

        return [];
    }

    private function assertComplete(EcfBooklet $booklet, EcfActivity $activity, EcfPart $part): void
    {
        $missing = $this->missing($booklet, $activity, $part);
        if ([] !== $missing) {
            throw new EcfRefusal($missing[0]);
        }
    }

    /** @param list<EcfActivityType> $types */
    private function assertSynthesisReady(EcfBooklet $booklet, array $types): void
    {
        $missing = $this->synthesisMissing($booklet, $types);
        if ([] !== $missing) {
            throw new EcfRefusal($missing[0]);
        }
    }

    // « Nom ▸ Tharaud »: the official sheet prints the family name alone.
    public static function printedName(User $user): string
    {
        $name = trim((string) $user->getLastname());

        return '' !== $name ? $name : ($user->getDisplayName() ?? $user->getUsername());
    }
}
