<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferentialTemplateKind;
use App\Repository\ReferentialTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The official template of one session, uploaded by the administration (design/validated/portfolio.md §9).
 *
 * **Never in the application's code.** The SIEC publishes a new file with every circulaire, and the
 * only honest way to fill it is to fill *that* file: it is inspected on arrival by landmarks, never
 * by fixed coordinates (App\Service\Portfolio\E5TemplateInspector), and what was found is kept in
 * `anchors`, so the writer puts values where the inspection saw the labels.
 *
 * A template nobody has put in service is invisible to students. No template in service for the
 * session means no .xlsx export - never the file of another year with its SESSION cell rewritten (R15).
 */
#[ORM\Entity(repositoryClass: ReferentialTemplateRepository::class)]
#[ORM\Table(name: 'referential_template')]
#[ORM\UniqueConstraint(name: 'referential_template_unique', columns: ['referential_id', 'session', 'kind'])]
class ReferentialTemplate
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Referential::class)]
    #[ORM\JoinColumn(name: 'referential_id', nullable: false, onDelete: 'CASCADE')]
    private ?Referential $referential = null;

    /** The examination session - `2027`. */
    #[ORM\Column]
    private int $session;

    #[ORM\Column(length: 30, enumType: ReferentialTemplateKind::class)]
    private ReferentialTemplateKind $kind;

    #[ORM\Column(name: 'file_key', length: 255)]
    private string $fileKey;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    /**
     * What the inspection found, cell by cell - see E5TemplateInspector::inspect().
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $anchors = [];

    #[ORM\Column(name: 'in_service_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inServiceAt = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /**
     * @param array<string, mixed> $anchors
     */
    public function __construct(Referential $referential, int $session, ReferentialTemplateKind $kind, string $fileKey, string $originalName, array $anchors)
    {
        $this->referential = $referential;
        $this->session = $session;
        $this->kind = $kind;
        $this->fileKey = $fileKey;
        $this->originalName = $originalName;
        $this->anchors = $anchors;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReferential(): ?Referential
    {
        return $this->referential;
    }

    public function getSession(): int
    {
        return $this->session;
    }

    public function getKind(): ReferentialTemplateKind
    {
        return $this->kind;
    }

    public function getFileKey(): string
    {
        return $this->fileKey;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    /**
     * A new file for the same session replaces the previous one and takes it out of service: the
     * inspection that allowed the old file says nothing about the new one.
     *
     * @param array<string, mixed> $anchors
     */
    public function replaceFile(string $fileKey, string $originalName, array $anchors): static
    {
        $this->fileKey = $fileKey;
        $this->originalName = $originalName;
        $this->anchors = $anchors;
        $this->inServiceAt = null;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAnchors(): array
    {
        return $this->anchors;
    }

    public function getInServiceAt(): ?\DateTimeImmutable
    {
        return $this->inServiceAt;
    }

    public function isInService(): bool
    {
        return null !== $this->inServiceAt;
    }

    /** The référentiel's columns changed: the inspection that allowed this file no longer holds. */
    public function withdraw(): static
    {
        $this->inServiceAt = null;

        return $this;
    }

    public function putInService(): static
    {
        $this->inServiceAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
