<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LessonLogAttachmentSourceType;
use App\Enum\LessonLogSection;
use App\Repository\LessonLogAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One "material" attached to a LessonLog: either an S3-backed upload ($storageKey set) or an
 * external link ($url set) - never both, enforced by App\Controller\LessonLogController rather
 * than here, since Doctrine has no cross-field XOR constraint.
 */
#[ORM\Entity(repositoryClass: LessonLogAttachmentRepository::class)]
#[ORM\Table(name: 'lesson_log_attachment')]
class LessonLogAttachment
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

    #[ORM\ManyToOne(targetEntity: LessonLog::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'lesson_log_id', nullable: false)]
    private ?LessonLog $lessonLog = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(length: 20, enumType: LessonLogAttachmentSourceType::class)]
    private ?LessonLogAttachmentSourceType $type = null;

    // S3 object key when $type is Upload (see App\Service\FileUploadService) - not a URL, keeps
    // the bucket/CloudFront domain changeable without a data migration.
    #[ORM\Column(name: 'storage_key', length: 255, nullable: true)]
    private ?string $storageKey = null;

    // External URL when $type is Link.
    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    /**
     * The part of the cahier de texte the document hangs off (mockup 2a). Attachments predating the
     * split into three parts are carried over onto « pendant la séance », which was the only place
     * they used to be displayed.
     */
    #[ORM\Column(length: 20, enumType: LessonLogSection::class)]
    private LessonLogSection $section = LessonLogSection::During;

    /**
     * Visibility date specific to the document, which departs from its section's - the case of the
     * correction published after the papers are handed in. Null = the document follows its section.
     */
    #[ORM\Column(name: 'visible_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $visibleAt = null;

    public function __construct(LessonLog $lessonLog, string $label)
    {
        $this->lessonLog = $lessonLog;
        $this->label = $label;

        if (!$lessonLog->getAttachments()->contains($this)) {
            $lessonLog->getAttachments()->add($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLessonLog(): ?LessonLog
    {
        return $this->lessonLog;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getType(): ?LessonLogAttachmentSourceType
    {
        return $this->type;
    }

    public function setType(?LessonLogAttachmentSourceType $type): static
    {
        $this->type = $type;

        return $this;
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

    public function getSection(): LessonLogSection
    {
        return $this->section;
    }

    public function setSection(LessonLogSection $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getVisibleAt(): ?\DateTimeImmutable
    {
        return $this->visibleAt;
    }

    public function setVisibleAt(?\DateTimeImmutable $visibleAt): static
    {
        $this->visibleAt = $visibleAt;

        return $this;
    }

    /**
     * A document is readable when its own date allows it, and failing that when its part is
     * published. The document's own date DEPARTS from the section, it does not add to it: that is
     * what the correction case calls for, filed on an already readable part but only published after
     * the papers are handed in.
     */
    public function isVisibleFor(?\DateTimeImmutable $now = null): bool
    {
        if (null !== $this->visibleAt) {
            return $this->visibleAt <= ($now ?? new \DateTimeImmutable());
        }

        return $this->lessonLog?->isSectionVisible($this->section, $now) ?? false;
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
