<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LearningPathStepVisitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The first time somebody opened a step of a path they follow.
 *
 * Two things read it: a course step is *done* once opened - a page opened proves nothing about
 * what was read, so it is ticked and never used as a lock - and **a step once opened stays open**,
 * whatever the author changes before it afterwards (a threshold raised, a quiz inserted). That is
 * the rule the access conditions of the platform already hold: what was begun stays reachable.
 */
#[ORM\Entity(repositoryClass: LearningPathStepVisitRepository::class)]
#[ORM\Table(name: 'learning_path_step_visit')]
#[ORM\UniqueConstraint(name: 'uniq_learning_path_step_visit', columns: ['enrollment_id', 'step_id'])]
class LearningPathStepVisit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LearningPathEnrollment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LearningPathEnrollment $enrollment = null;

    #[ORM\ManyToOne(targetEntity: LearningPathStep::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LearningPathStep $step = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    public function __construct(LearningPathEnrollment $enrollment, LearningPathStep $step)
    {
        $this->enrollment = $enrollment;
        $this->step = $step;
        $this->openedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnrollment(): LearningPathEnrollment
    {
        \assert(null !== $this->enrollment);

        return $this->enrollment;
    }

    public function getStep(): LearningPathStep
    {
        \assert(null !== $this->step);

        return $this->step;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }
}
