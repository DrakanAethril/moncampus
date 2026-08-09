<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobSearchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The state of a student's job search: open, or closed by a teacher through "Mark as finished"
 * (design_handoff_stage_alternance, screen 1a).
 *
 * Closing a search has three effects, spelled out by the handoff: the mailbox **stays readable**,
 * sending is turned off, and the student **drops out of the reminders**. It is therefore neither a
 * deletion nor an archive - hence a dedicated entity rather than a flag on the student: we want to
 * know *who* closed it and *when*, a mistaken closure having to be explainable and undoable.
 *
 * A row only exists for students whose search has been closed: having no row is the normal state.
 */
#[ORM\Entity(repositoryClass: JobSearchRepository::class)]
#[ORM\Table(name: 'job_search')]
#[ORM\UniqueConstraint(name: 'uniq_job_search_student', columns: ['student_id'])]
class JobSearch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $closedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'closed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy = null;

    public function __construct()
    {
        $this->closedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function setStudent(?User $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function setClosedBy(?User $closedBy): static
    {
        $this->closedBy = $closedBy;

        return $this;
    }
}
