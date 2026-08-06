<?php

namespace App\Entity;

use App\Repository\AssignmentDismissalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Ignorer": a student setting one of their own assignments aside (design_handoff_travail_a_faire,
 * screen 3c). A dismissed assignment is no longer flagged as late; one still due in the future
 * stays visible, greyed out, with "Rétablir", while one already late drops off the list.
 *
 * Same shape as AssignmentCompletion: one row per (assignment, student), written on dismissal and
 * deleted on restore - no row means "not dismissed". This is not a claim of completion, hence a
 * table of its own: ignoring is not doing.
 */
#[ORM\Entity(repositoryClass: AssignmentDismissalRepository::class)]
#[ORM\Table(name: 'assignment_dismissal')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_dismissal', columns: ['assignment_id', 'student_id'])]
class AssignmentDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false, onDelete: 'CASCADE')]
    private ?Assignment $assignment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'dismissed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dismissedAt;

    public function __construct(Assignment $assignment, User $student)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->dismissedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getDismissedAt(): \DateTimeImmutable
    {
        return $this->dismissedAt;
    }
}
