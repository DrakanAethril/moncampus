<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineCoursePageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A teacher's public page - `/courses/{handle}` - which lists their published courses and nothing
 * else (design/validated/cours-en-ligne.md, §5). One per teacher; there is deliberately no page
 * above it that would list the teachers.
 *
 * `$handle` is the address the teacher chose, never their login. It may change at any time, before
 * and during diffusion: every address a page ever carried stays a row of
 * App\Entity\OnlineCoursePageHandle, which is what redirects the old one and keeps it from being
 * handed to somebody else. Change it through App\Service\OnlineCourse\OnlineCoursePageHandles
 * only - the setter here does not write that row.
 */
#[ORM\Entity(repositoryClass: OnlineCoursePageRepository::class)]
#[ORM\Table(name: 'online_course_page')]
#[ORM\UniqueConstraint(name: 'uniq_online_course_page_owner', columns: ['owner_id'])]
#[ORM\UniqueConstraint(name: 'uniq_online_course_page_handle', columns: ['handle'])]
class OnlineCoursePage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 60)]
    private string $handle;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $title;

    /** Sanitized HTML, shown under the title. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $introduction = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $handle, string $title)
    {
        $this->owner = $owner;
        $this->handle = $handle;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        \assert(null !== $this->owner);

        return $this->owner;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner === $user;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    /** Only App\Service\OnlineCourse\OnlineCoursePageHandles calls this - see the class docblock. */
    public function setHandle(string $handle): static
    {
        $this->handle = $handle;
        $this->touch();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->touch();

        return $this;
    }

    public function getIntroduction(): ?string
    {
        return $this->introduction;
    }

    public function setIntroduction(?string $introduction): static
    {
        $this->introduction = null === $introduction || '' === trim($introduction) ? null : $introduction;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
