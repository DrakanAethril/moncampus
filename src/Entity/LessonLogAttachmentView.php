<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LessonLogAttachmentViewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'ouverture d'un document du cahier de texte par un étudiant.
 *
 * Plus fin que la consultation d'un travail (App\Entity\AssignmentView) : ici la trace est posée au
 * clic sur le document lui-même, pas sur la page qui le mentionne. C'est ce qui permet de dire
 * qu'un étudiant a ouvert le support, et non seulement qu'il a vu son nom.
 *
 * Une ligne par (document, étudiant), écrite à la première ouverture puis mise à jour : la première
 * date dit quand l'étudiant a ouvert le document, la dernière quand il y est revenu.
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
