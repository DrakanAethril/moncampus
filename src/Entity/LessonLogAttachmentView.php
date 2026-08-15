<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LessonLogAttachmentViewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student's opening of a cahier de texte document.
 *
 * Finer-grained than the consultation of an assignment (App\Entity\AssignmentView): here the trace is
 * recorded on the click on the document itself, not on the page mentioning it. That is what allows
 * saying a student opened the support, and not merely that they saw its name.
 *
 * One row per (document, student), written on the first opening then updated: the first date says
 * when the student opened the document, the last when they came back to it.
 */
#[ORM\Entity(repositoryClass: LessonLogAttachmentViewRepository::class)]
#[ORM\Table(name: 'lesson_log_attachment_view')]
#[ORM\UniqueConstraint(name: 'uniq_attachment_view', columns: ['attachment_id', 'student_id'])]
class LessonLogAttachmentView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LessonLogAttachment::class)]
    #[ORM\JoinColumn(name: 'attachment_id', nullable: false, onDelete: 'CASCADE')]
    private ?LessonLogAttachment $attachment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'student_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(name: 'first_opened_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstOpenedAt;

    #[ORM\Column(name: 'last_opened_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastOpenedAt;

    #[ORM\Column(name: 'open_count')]
    private int $openCount = 1;

    public function __construct(LessonLogAttachment $attachment, User $student)
    {
        $this->attachment = $attachment;
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

    public function getAttachment(): ?LessonLogAttachment
    {
        return $this->attachment;
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
