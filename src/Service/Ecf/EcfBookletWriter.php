<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfActivity;
use App\Entity\EcfBooklet;
use App\Entity\EcfEvaluationRow;
use App\Entity\User;
use App\Enum\EcfCivility;
use App\Enum\EcfPart;
use App\Enum\EcfResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every write of an ECF booklet except the visas (design/validated/ecf-booklet.md, R6).
 *
 * A signed part refuses any write: what was signed stays what is printed, until its visas are taken
 * back (EcfSigner::unsign()). A closed booklet refuses everything. The complementary page only opens
 * once the main sheet is signed « non satisfait » - it exists to re-evaluate.
 */
class EcfBookletWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EcfMastery $mastery,
    ) {
    }

    public function saveCover(EcfBooklet $booklet, ?EcfCivility $civility, ?\DateTimeImmutable $birthDate, User $actor): void
    {
        $this->refuseIfClosed($booklet);
        $booklet->setCivility($civility)->setBirthDate($birthDate);
        $this->stamp($booklet, $actor);
        $this->entityManager->flush();
    }

    public function saveSheet(EcfBooklet $booklet, string $groupCode, EcfPart $part, EcfSheetInput $input, User $actor): EcfActivity
    {
        if (EcfPart::Synthesis === $part) {
            throw new \InvalidArgumentException('The synthesis is saved by saveSynthesis().');
        }

        $this->refuseIfClosed($booklet);
        $activity = $booklet->activityFor($groupCode) ?? new EcfActivity($booklet, $groupCode);

        if ($this->mastery->isSigned($booklet, $activity, $part)) {
            throw new EcfRefusal('ecfRefusalPartSignedMessage');
        }

        if (EcfPart::Complementary === $part && !$this->complementaryOpen($booklet, $activity)) {
            throw new EcfRefusal('ecfRefusalComplementaryClosedMessage');
        }

        $rows = array_values(array_filter($input->rows, static fn (EcfRowInput $row): bool => !$row->isBlank()));
        $max = $part->maxRows();
        if (null !== $max && \count($rows) > $max) {
            throw new EcfRefusal('ecfRefusalTooManyRowsMessage', ['%max%' => (string) $max]);
        }

        foreach ($activity->rowsOf($part) as $existing) {
            $activity->removeRow($existing);
        }
        foreach ($rows as $index => $row) {
            (new EcfEvaluationRow($activity, $part, $index + 1))
                ->setDescription(trim($row->description))
                ->setEvaluatedOn($row->evaluatedOn)
                ->setCompetences($row->competences);
        }

        if (EcfPart::Main === $part) {
            $activity->setResult($input->result);
            $notSatisfied = EcfResult::NotSatisfied === $input->result;
            // The two « non satisfait » zones only mean something under that result: a sheet
            // switched back to « satisfait » forgets them rather than printing them.
            $activity->setAttentionPoints($notSatisfied ? self::text($input->attentionPoints) : null);
            $activity->setReassessNote($notSatisfied ? self::text($input->reassessNote) : null);
            $activity->setReassessCompetences($notSatisfied ? array_values(array_unique(array_filter($input->reassessCompetences, static fn (int $n): bool => $n >= 1 && $n <= EcfEvaluationRow::MAX_COMPETENCE))) : []);
        } else {
            $activity->setComplementaryResult($input->result);
            $activity->setComplementaryObservations(self::text($input->observations));
        }

        $this->stamp($booklet, $actor);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        return $activity;
    }

    public function saveSynthesis(EcfBooklet $booklet, ?string $observations, ?\DateTimeImmutable $remittedOn, User $actor): void
    {
        if ($this->mastery->isSigned($booklet, null, EcfPart::Synthesis)) {
            throw new EcfRefusal('ecfRefusalPartSignedMessage');
        }

        $booklet->setSynthesisObservations(self::text($observations))->setRemittedOn($remittedOn);
        $this->stamp($booklet, $actor);
        $this->entityManager->flush();
    }

    /**
     * The date of remise is the one line written after the representative closes the booklet: the
     * copy is handed over once the booklet is complete.
     */
    public function saveRemittance(EcfBooklet $booklet, ?\DateTimeImmutable $remittedOn, User $actor): void
    {
        $booklet->setRemittedOn($remittedOn);
        $this->stamp($booklet, $actor);
        $this->entityManager->flush();
    }

    public function complementaryOpen(EcfBooklet $booklet, ?EcfActivity $activity): bool
    {
        return null !== $activity
            && EcfResult::NotSatisfied === $activity->getResult()
            && $this->mastery->isSigned($booklet, $activity, EcfPart::Main);
    }

    private function refuseIfClosed(EcfBooklet $booklet): void
    {
        if ($booklet->isClosed()) {
            throw new EcfRefusal('ecfRefusalClosedMessage');
        }
    }

    private function stamp(EcfBooklet $booklet, User $actor): void
    {
        if (null === $booklet->getId()) {
            $booklet->setCreatedBy($actor);
            $this->entityManager->persist($booklet);

            return;
        }

        $booklet->setLastUpdatedBy($actor);
        $booklet->setLastUpdatedDate(new \DateTimeImmutable());
    }

    private static function text(?string $value): ?string
    {
        $value = null === $value ? '' : trim(str_replace("\r\n", "\n", $value));

        return '' === $value ? null : $value;
    }
}
