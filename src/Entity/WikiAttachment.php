<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WikiAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file joined to one page of a wiki - node-scoped, and otherwise a copy of
 * App\Entity\DocumentationArticleAttachment, deliberately and not by coincidence: the two features
 * attach the same kind of file for the same reason, so they are drawn the same way and stored the
 * same way (S3 via App\Service\FileUploadService, the key kept rather than a URL).
 *
 * The uploads it accepts are the platform rule unnarrowed - the wiki is the general-purpose
 * workspace, so it is the one field in the app that restricts nothing (design/validated/
 * upload-policy.md).
 */
#[ORM\Entity(repositoryClass: WikiAttachmentRepository::class)]
#[ORM\Table(name: 'wiki_attachment')]
class WikiAttachment
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

    #[ORM\ManyToOne(targetEntity: WikiNode::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'node_id', nullable: false, onDelete: 'CASCADE')]
    private ?WikiNode $node = null;

    // The name the reader sees and downloads under - the uploaded file's own name.
    #[ORM\Column(length: 255)]
    private string $label;

    // S3 object key, not a URL: the bucket/CloudFront domain stays changeable without a data
    // migration.
    #[ORM\Column(name: 'storage_key', length: 255)]
    private string $storageKey;

    #[ORM\Column(name: 'mime_type', length: 150, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(name: 'size_bytes', nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $label, string $storageKey)
    {
        $this->label = $label;
        $this->storageKey = $storageKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNode(): ?WikiNode
    {
        return $this->node;
    }

    public function setNode(?WikiNode $node): static
    {
        $this->node = $node;

        return $this;
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

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(?int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * "PDF", "Word", "Image"... - the short format word the attachment card shows next to the
     * size, and the one the PDF export lists. Read from the file name rather than the MIME type,
     * which says "application/msword" where the reader expects "Word".
     */
    public function getFormatLabel(): string
    {
        $extension = strtoupper(pathinfo($this->label, \PATHINFO_EXTENSION));

        return match ($extension) {
            '' => 'Fichier',
            'DOC', 'DOCX', 'ODT' => 'Word',
            'XLS', 'XLSX', 'ODS' => 'Excel',
            'PPT', 'PPTX', 'ODP' => 'PowerPoint',
            'JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'AVIF' => 'Image',
            default => $extension,
        };
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
