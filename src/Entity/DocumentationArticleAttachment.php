<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentationArticleAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file joined to a DocumentationArticle - the "Pièces jointes" block of the handoff (2c/2d/2e).
 * Always an S3-backed upload (App\Service\FileUploadService), never an external link: the block is
 * drawn as downloadable cards with a size and a format, which a link cannot provide.
 */
#[ORM\Entity(repositoryClass: DocumentationArticleAttachmentRepository::class)]
#[ORM\Table(name: 'documentation_article_attachment')]
class DocumentationArticleAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DocumentationArticle::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'article_id', nullable: false, onDelete: 'CASCADE')]
    private ?DocumentationArticle $article = null;

    // The name the reader sees and downloads under - the uploaded file's own name.
    #[ORM\Column(length: 255)]
    private string $label;

    // S3 object key, not a URL: the bucket/CloudFront domain stays changeable without a data
    // migration (same convention as App\Entity\LessonLogAttachment).
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

    public function getArticle(): ?DocumentationArticle
    {
        return $this->article;
    }

    public function setArticle(?DocumentationArticle $article): static
    {
        $this->article = $article;

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
     * size. Read from the file name rather than the MIME type, which says "application/msword"
     * where the reader expects "Word".
     */
    public function getFormatLabel(): string
    {
        $extension = strtoupper(pathinfo($this->label, \PATHINFO_EXTENSION));

        return match ($extension) {
            '' => 'Fichier',
            'DOC', 'DOCX', 'ODT' => 'Word',
            'XLS', 'XLSX', 'ODS' => 'Excel',
            'PPT', 'PPTX', 'ODP' => 'PowerPoint',
            'JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'SVG' => 'Image',
            default => $extension,
        };
    }
}
