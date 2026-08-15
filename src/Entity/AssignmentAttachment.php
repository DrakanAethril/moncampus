<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssignmentAttachmentSourceType;
use App\Repository\AssignmentAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A support or an example attached to an assignment (design_handoff_creation_travail 2a, « Documents
 * et liens »): either a file uploaded to S3 ($storageKey), or an external link ($url) - never both,
 * like LessonLogAttachment, of which it is the assignment-side counterpart.
 *
 * Nothing is written before the assignment is published: the files stay in the form as long as the
 * wizard has not run its course, and are only uploaded once the assignment exists.
 */
#[ORM\Entity(repositoryClass: AssignmentAttachmentRepository::class)]
#[ORM\Table(name: 'assignment_attachment')]
class AssignmentAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Assignment::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'assignment_id', nullable: false, onDelete: 'CASCADE')]
    private ?Assignment $assignment = null;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 20, enumType: AssignmentAttachmentSourceType::class)]
    private AssignmentAttachmentSourceType $type;

    // S3 object key for an uploaded file - not a URL, see App\Service\FileUploadService.
    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    public function __construct(Assignment $assignment, string $label, AssignmentAttachmentSourceType $type)
    {
        $this->assignment = $assignment;
        $this->label = $label;
        $this->type = $type;

        if (!$assignment->getAttachments()->contains($this)) {
            $assignment->getAttachments()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getType(): AssignmentAttachmentSourceType
    {
        return $this->type;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function setStorageKey(?string $storageKey): static
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function isLink(): bool
    {
        return AssignmentAttachmentSourceType::Link === $this->type;
    }
}
