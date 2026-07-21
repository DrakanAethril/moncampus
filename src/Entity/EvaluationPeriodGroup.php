<?php

namespace App\Entity;

use App\Repository\EvaluationPeriodGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A named set of grading periods (e.g. "Semestres Campus" -> Semestre 1, Semestre 2) a Program can
 * link to (App\Entity\Program::$evaluationPeriodGroup, 0 or 1) so the Carnet de notes tool can
 * offer a period selector and filter/average evaluations by date range. Deliberately a separate
 * entity from App\Entity\PeriodGroup/Period (the establishment's broader school-calendar
 * structure, SchoolYear-scoped and carrying a PeriodType/Modality it doesn't need here) - see
 * design/design_handoff_projet/PROMPT_CLAUDE_CODE_carnet_de_notes.md.
 *
 * Deactivating a group doesn't delete anything - it just stops being offered for new Program
 * links/evaluations (App\Repository\EvaluationPeriodGroupRepository::applyActiveFilter()).
 */
#[ORM\Entity(repositoryClass: EvaluationPeriodGroupRepository::class)]
#[ORM\Table(name: 'evaluation_period_group')]
class EvaluationPeriodGroup
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    /** @var Collection<int, EvaluationPeriod> */
    #[ORM\OneToMany(targetEntity: EvaluationPeriod::class, mappedBy: 'evaluationPeriodGroup', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['startDate' => 'ASC'])]
    private Collection $periods;

    /** @var Collection<int, Program> */
    #[ORM\OneToMany(targetEntity: Program::class, mappedBy: 'evaluationPeriodGroup')]
    private Collection $programs;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->creationDate = new \DateTimeImmutable();
        $this->periods = new ArrayCollection();
        $this->programs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return Collection<int, EvaluationPeriod> */
    public function getPeriods(): Collection
    {
        return $this->periods;
    }

    public function addPeriod(EvaluationPeriod $period): static
    {
        if (!$this->periods->contains($period)) {
            $this->periods->add($period);
            $period->setEvaluationPeriodGroup($this);
        }

        return $this;
    }

    public function removePeriod(EvaluationPeriod $period): static
    {
        $this->periods->removeElement($period);

        return $this;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }

    // Server-side mirror of the client-side inline check (design 12b) - every pair of entries in
    // this group must not overlap. O(n^2) but n is always small (a handful of terms/semesters per
    // group).
    #[Assert\Callback]
    public function validateNoOverlappingPeriods(ExecutionContextInterface $context): void
    {
        $periods = array_values(array_filter(
            $this->periods->toArray(),
            static fn (EvaluationPeriod $period): bool => null !== $period->getStartDate() && null !== $period->getEndDate(),
        ));

        foreach ($periods as $i => $a) {
            foreach ($periods as $j => $b) {
                if ($j <= $i) {
                    continue;
                }

                if ($a->getStartDate() <= $b->getEndDate() && $b->getStartDate() <= $a->getEndDate()) {
                    $context->buildViolation('evaluationPeriodOverlapMessage')
                        ->setParameter('%a%', $a->getName())
                        ->setParameter('%b%', $b->getName())
                        ->setParameter('%bStart%', $b->getStartDate()->format('d/m/Y'))
                        ->setParameter('%bEnd%', $b->getEndDate()->format('d/m/Y'))
                        ->atPath('periods')
                        ->addViolation();
                }
            }
        }
    }
}
