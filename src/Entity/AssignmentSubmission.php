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
 *
 * An assignment that spells out what it expects gets one submission per expected production, each
 * with its own deadline and its own "Déposer" button (design_handoff_travail_a_faire, screen 3b) -
 * hence $expectedProduction and the (assignment, student, production) uniqueness. Assignments
 * without a detailed breakdown keep the single production-less submission they always had.
 */
#[ORM\Entity(repositoryClass: AssignmentSubmissionRepository::class)]
#[ORM\Table(name: 'assignment_submission')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_student', columns: ['assignment_id', 'student_id', 'expected_production_id'])]
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

    /**
     * Which expected production this submission answers, or null for the assignment as a whole -
     * the shape every pre-existing submission has, and the one an assignment without a detailed
     * breakdown keeps producing.
     */
    #[ORM\ManyToOne(targetEntity: AssignmentExpectedProduction::class)]
    #[ORM\JoinColumn(name: 'expected_production_id', nullable: true, onDelete: 'CASCADE')]
    private ?AssignmentExpectedProduction $expectedProduction = null;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $submittedAt = null;

    /** @var Collection<int, AssignmentSubmissionFile> */
    #[ORM\OneToMany(mappedBy: 'submission', targetEntity: AssignmentSubmissionFile::class, orphanRemoval: true)]
    private Collection $files;

    public function __construct(Assignment $assignment, User $student, ?AssignmentExpectedProduction $expectedProduction = null)
    {
        $this->files = new ArrayCollection();
        $this->assignment = $assignment;
        $this->student = $student;
        $this->expectedProduction = $expectedProduction;
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

    public function getExpectedProduction(): ?AssignmentExpectedProduction
    {
        return $this->expectedProduction;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /**
     * The deadline this submission was actually held to: the production's own when it has one,
     * the assignment's otherwise.
     */
    public function getEffectiveDueDate(): ?\DateTimeImmutable
    {
        return $this->expectedProduction?->getEffectiveDueDate() ?? $this->assignment?->getDueDate();
    }

    /** @return Collection<int, AssignmentSubmissionFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }
}
