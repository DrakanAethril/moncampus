<?php

namespace App\Entity;

use App\Repository\EvaluationPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One entry within an EvaluationPeriodGroup (e.g. "Semestre 1"). The edit form only ever collects
 * a plain date for start/end - setStartDate()/setEndDate() below pin the time to 00:00:00/23:59:59
 * themselves (design's "date de début (fixée à 00h00), date de fin (fixée à 23h59)"), so every
 * comparison against an Evaluation's own date/time (App\Service\EvaluationAverageCalculator's
 * period filtering) is unambiguous at the boundary.
 */
#[ORM\Entity(repositoryClass: EvaluationPeriodRepository::class)]
#[ORM\Table(name: 'evaluation_period')]
class EvaluationPeriod
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    // Nullable in PHP only so the form's empty_data can pass a transiently-null value before the
    // field is actually submitted - same reasoning as App\Entity\Period::$startDate.
    #[ORM\Column(name: 'start_date', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriodGroup::class, inversedBy: 'periods')]
    #[ORM\JoinColumn(name: 'evaluation_period_group_id', nullable: false)]
    private ?EvaluationPeriodGroup $evaluationPeriodGroup = null;

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

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate?->setTime(0, 0, 0);

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate?->setTime(23, 59, 59);

        return $this;
    }

    public function getEvaluationPeriodGroup(): ?EvaluationPeriodGroup
    {
        return $this->evaluationPeriodGroup;
    }

    public function setEvaluationPeriodGroup(?EvaluationPeriodGroup $evaluationPeriodGroup): static
    {
        $this->evaluationPeriodGroup = $evaluationPeriodGroup;

        if (null !== $evaluationPeriodGroup && !$evaluationPeriodGroup->getPeriods()->contains($this)) {
            $evaluationPeriodGroup->getPeriods()->add($this);
        }

        return $this;
    }

    // Whether $date falls within this entry's [00:00, 23:59:59] range - the sole mechanism
    // Evaluations are matched to a period (there is no stored FK on Evaluation itself, see that
    // entity's docblock).
    public function contains(\DateTimeImmutable $date): bool
    {
        return null !== $this->startDate && null !== $this->endDate && $date >= $this->startDate && $date <= $this->endDate;
    }
}
