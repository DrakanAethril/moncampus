<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobSearchNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A staff note about a student's job search (design_handoff_stage_alternance, screen 2a: "Notes
 * d'accompagnement", flagged "Équipe uniquement").
 *
 * Attached to the student rather than to one application, because that is how the mockup reads them
 * - "at ease speaking, cover letter to shorten", "aiming only at Limoges" are observations about the
 * search as a whole, not about one company.
 *
 * **Never visible to the student.** No screen on the student side reads this table, and none should:
 * a note written to be read between teachers stops being written honestly the day the student can
 * read it too.
 */
#[ORM\Entity(repositoryClass: JobSearchNoteRepository::class)]
#[ORM\Table(name: 'job_search_note')]
#[ORM\Index(name: 'idx_job_search_note_student', columns: ['student_id'])]
class JobSearchNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
