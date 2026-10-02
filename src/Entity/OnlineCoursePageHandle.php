<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineCoursePageHandleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Every address a teacher's page has ever carried, the current one included
 * (design/validated/cours-en-ligne.md, §4).
 *
 * The UNIQUE index on `handle` carries the whole rule, the way `user_login` does for logins: an
 * address somebody left still has its row, so it can neither be taken by another teacher nor stop
 * answering - an old link redirects to the page's current address. The page that left it may take
 * it back, since the row is already its own.
 */
#[ORM\Entity(repositoryClass: OnlineCoursePageHandleRepository::class)]
#[ORM\Table(name: 'online_course_page_handle')]
#[ORM\UniqueConstraint(name: 'uniq_online_course_page_handle_handle', columns: ['handle'])]
class OnlineCoursePageHandle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OnlineCoursePage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OnlineCoursePage $page = null;

    #[ORM\Column(length: 60)]
    private string $handle;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(OnlineCoursePage $page, string $handle)
    {
        $this->page = $page;
        $this->handle = $handle;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPage(): OnlineCoursePage
    {
        \assert(null !== $this->page);

        return $this->page;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
