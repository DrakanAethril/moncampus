<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssignmentDismissalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Ignorer": a student setting one of their own deadlines aside (design_handoff_travail_a_faire,
 * screen 3c). A dismissed deadline is no longer flagged as late; one still due in the future stays
 * visible, greyed out, with "Rétablir", while one already late drops off the list.
 *
 * Same shape as AssignmentCompletion: one row per (assignment, student, expected production),
 * written on dismissal and deleted on restore - no row means "not dismissed". This is not a claim
 * of completion, hence a table of its own: ignoring is not doing.
 *
 * $expectedProduction is what the student clicked on, and only that: a work asking for several
 * dated productions is read on one line per production, so setting one aside must leave the others
 * standing. It is null when the line stands for the assignment as a whole - a quiz, a listening, a
 * work to declare done, or a deposit with no production spelled out - which is the only case where
 * dismissing carries the whole assignment.
 */
#[ORM\Entity(repositoryClass: AssignmentDismissalRepository::class)]
#[ORM\Table(name: 'assignment_dismissal')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_dismissal', columns: ['assignment_id', 'student_id', 'expected_production_id'])]
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

    #[ORM\ManyToOne(targetEntity: AssignmentExpectedProduction::class)]
    #[ORM\JoinColumn(name: 'expected_production_id', nullable: true, onDelete: 'CASCADE')]
    private ?AssignmentExpectedProduction $expectedProduction = null;

    #[ORM\Column(name: 'dismissed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dismissedAt;

    public function __construct(Assignment $assignment, User $student, ?AssignmentExpectedProduction $expectedProduction = null)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->expectedProduction = $expectedProduction;
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

    public function getExpectedProduction(): ?AssignmentExpectedProduction
    {
        return $this->expectedProduction;
    }

    public function getDismissedAt(): \DateTimeImmutable
    {
        return $this->dismissedAt;
    }
}
