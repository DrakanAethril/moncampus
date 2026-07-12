<?php

namespace App\Entity;

use App\Repository\AssignmentSubmissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One student's submission for one Assignment - created lazily on their first file upload.
 * $submittedAt is stamped once, at creation, and never changes: resubmission means adding more
 * files to the same row (see AssignmentSubmissionFile), not creating a new submission or bumping
 * the timestamp, so on-time/late status reflects when the student first engaged, not their most
 * recent edit.
 */
#[ORM\Entity(repositoryClass: AssignmentSubmissionRepository::class)]
#[ORM\Table(name: 'assignment_submission')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_student', columns: ['assignment_id', 'student_id'])]
class AssignmentSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false)]
    private ?Assignment $assignment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false)]
    private ?User $student = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $submittedAt = null;

    /** @var Collection<int, AssignmentSubmissionFile> */
    #[ORM\OneToMany(mappedBy: 'submission', targetEntity: AssignmentSubmissionFile::class, orphanRemoval: true)]
    private Collection $files;

    public function __construct(Assignment $assignment, User $student)
    {
        $this->files = new ArrayCollection();
        $this->assignment = $assignment;
        $this->student = $student;
        $this->submittedAt = new \DateTimeImmutable();
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

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /** @return Collection<int, AssignmentSubmissionFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }
}
