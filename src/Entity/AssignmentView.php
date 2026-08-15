<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssignmentViewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student's consultation of an assignment: the trace that the assignment's page - its instructions
 * and its documents - was opened.
 *
 * This is what feeds the cahier de texte's « ouvert par 19 / 24 », replacing the « marquer comme
 * fait » declaration, which remained a student's word. An opening is not proof of reading, but it is
 * an observed fact, dated, and one the student does not choose to produce - which is what makes it
 * more reliable.
 *
 * One row per (assignment, student), written on the first opening then only updated: the first date
 * says when the student became aware of the assignment, the last when they came back to it, and the
 * counter how many times.
 */
#[ORM\Entity(repositoryClass: AssignmentViewRepository::class)]
#[ORM\Table(name: 'assignment_view')]
#[ORM\UniqueConstraint(name: 'uniq_assignment_view', columns: ['assignment_id', 'student_id'])]
class AssignmentView
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

    #[ORM\Column(name: 'first_viewed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstViewedAt;

    #[ORM\Column(name: 'last_viewed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastViewedAt;

    #[ORM\Column(name: 'view_count')]
    private int $viewCount = 1;

    public function __construct(Assignment $assignment, User $student)
    {
        $this->assignment = $assignment;
        $this->student = $student;
        $this->firstViewedAt = new \DateTimeImmutable();
        $this->lastViewedAt = $this->firstViewedAt;
    }

    public function registerView(): static
    {
        $this->lastViewedAt = new \DateTimeImmutable();
        ++$this->viewCount;

        return $this;
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

    public function getFirstViewedAt(): \DateTimeImmutable
    {
        return $this->firstViewedAt;
    }

    public function getLastViewedAt(): \DateTimeImmutable
    {
        return $this->lastViewedAt;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }
}
