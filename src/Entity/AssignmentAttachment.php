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

    /**
     * The library file this row was linked from, when it was one
     * (design/validated/file-library.md, "The link, on nine existing tables").
     *
     * **The row keeps its own storage key**, copied from the node. That is the decision that makes
     * the whole feature cheap: every reader - a Twig template, file_url(), the mobile API, the PDF
     * exports, the mail attachment builder - is untouched, because nothing about *reading* a file
     * changes. This foreign key exists for one purpose: to answer "where is this file used", with a
     * real index and a real constraint rather than a polymorphic (target_type, target_id) table
     * Doctrine could not key - and which nothing would clean up when a host disappears.
     *
     * `SET NULL` and never `CASCADE`: removing the links is App\Service\FileLibraryLinks's job, done
     * deliberately when the teacher confirms « Supprimer partout ». A cascade here would delete the
     * *host* row - the quiz question, the séquence resource, the video and its statistics - as a
     * database side effect nobody can see.
     */
    #[ORM\ManyToOne(targetEntity: FileLibraryNode::class)]
    #[ORM\JoinColumn(name: 'library_node_id', nullable: true, onDelete: 'SET NULL')]
    private ?FileLibraryNode $libraryNode = null;

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

    public function getLibraryNode(): ?FileLibraryNode
    {
        return $this->libraryNode;
    }

    public function setLibraryNode(?FileLibraryNode $libraryNode): self
    {
        $this->libraryNode = $libraryNode;

        return $this;
    }
}
