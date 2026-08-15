<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssignmentCompletionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Marquer comme fait »: a student's declaration on an assignment that expects neither a submission
 * nor an attempt (mockup 4a). A reading, a revision, exercises in a notebook have no proof of
 * completion other than the student's word.
 *
 * One row per (assignment, student), created on declaration and deleted if the student takes it back
 * - the absence of a row therefore means « to do », which avoids having to write one for every
 * student when an assignment is created. File submission has its own trace, AssignmentSubmission.
 */
#[ORM\Entity(repositoryClass: AssignmentCompletionRepository::class)]
#[ORM\Table(name: 'assignment_completion')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_completion', columns: ['assignment_id', 'student_id'])]
class AssignmentCompletion
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

    #[ORM\Column(name: 'done_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $doneAt;

    public function __construct(Assignment $assignment, User $student)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->doneAt = new \DateTimeImmutable();
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

    public function getDoneAt(): \DateTimeImmutable
    {
        return $this->doneAt;
    }
}
