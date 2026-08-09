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
     * Le temps du cahier de texte auquel le document se rattache (maquette 2a). Les pièces jointes
     * antérieures au découpage en trois temps sont reprises sur « pendant la séance », qui était le
     * seul endroit où elles s'affichaient.
     */
    #[ORM\Column(length: 20, enumType: LessonLogSection::class)]
    private LessonLogSection $section = LessonLogSection::During;

    /**
     * Date de visibilité propre au document, qui déroge à celle de sa section - le cas de la
     * correction publiée après la remise des copies. Null = le document suit sa section.
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
     * Un document se lit quand sa date propre le permet, et à défaut quand son temps est publié.
     * La date propre DÉROGE à la section, elle ne s'y ajoute pas : c'est ce que veut le cas de la
     * correction, déposée sur un temps déjà lisible mais publiée seulement après la remise.
     */
    public function isVisibleFor(?\DateTimeImmutable $now = null): bool
    {
        if (null !== $this->visibleAt) {
            return $this->visibleAt <= ($now ?? new \DateTimeImmutable());
        }

        return $this->lessonLog?->isSectionVisible($this->section, $now) ?? false;
    }
}
