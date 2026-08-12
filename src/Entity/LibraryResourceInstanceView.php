<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LibraryResourceInstanceViewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student opening a resource of the course space.
 *
 * The same shape as App\Entity\LessonLogAttachmentView, and for the same reason: the trace is
 * written when the resource itself is opened, not when the page listing it is displayed - which is
 * what lets a teacher say a student read the handout rather than merely saw its title.
 *
 * One row per (resource, student), written on the first opening then updated: the first date says
 * when the student opened it, the last one when they came back.
 *
 * It also has a second job. The access conditions of the course space read it to answer
 * "resource_viewed", so this table is what makes "the next chapter opens once the brief has been
 * read" expressible at all.
 */
#[ORM\Entity(repositoryClass: LibraryResourceInstanceViewRepository::class)]
#[ORM\Table(name: 'library_resource_instance_view')]
#[ORM\UniqueConstraint(name: 'uniq_library_resource_instance_view', columns: ['resource_id', 'student_id'])]
class LibraryResourceInstanceView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LibraryResourceInstance::class)]
    #[ORM\JoinColumn(name: 'resource_id', nullable: false, onDelete: 'CASCADE')]
    private ?LibraryResourceInstance $resource = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'first_opened_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstOpenedAt;

    #[ORM\Column(name: 'last_opened_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastOpenedAt;

    #[ORM\Column(name: 'open_count')]
    private int $openCount = 1;

    public function __construct(LibraryResourceInstance $resource, User $student)
    {
        $this->resource = $resource;
        $this->student = $student;
        $this->firstOpenedAt = new \DateTimeImmutable();
        $this->lastOpenedAt = $this->firstOpenedAt;
    }

    public function registerOpening(): static
    {
        $this->lastOpenedAt = new \DateTimeImmutable();
        ++$this->openCount;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResource(): ?LibraryResourceInstance
    {
        return $this->resource;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getFirstOpenedAt(): \DateTimeImmutable
    {
        return $this->firstOpenedAt;
    }

    public function getLastOpenedAt(): \DateTimeImmutable
    {
        return $this->lastOpenedAt;
    }

    public function getOpenCount(): int
    {
        return $this->openCount;
    }
}
