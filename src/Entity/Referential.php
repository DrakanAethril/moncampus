<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferentialBlockRole;
use App\Enum\ReferentialSynthesisModel;
use App\Repository\ReferentialRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A national référentiel of competencies, as published by France compétences
 * (design/validated/portfolio.md §7-8) - the BTS SIO's RNCP40792 for one.
 *
 * **Distinct from the livret's App\Entity\SkillGroup / Skill on purpose.** Those are copied per
 * formation and per year and serve the tutor's evaluation; this is one national text, shared by
 * every class that prepares the diploma and read word for word into the official synthesis table.
 *
 * **A new fiche is a new version**, never an edit of this one (R1): a SIO 2 finishes on the
 * référentiel it started on while the SIO 1 of the same year starts on the renovated one. `replaces`
 * says which version this one follows.
 */
#[ORM\Entity(repositoryClass: ReferentialRepository::class)]
#[ORM\Table(name: 'referential')]
#[ORM\UniqueConstraint(name: 'referential_code_version_unique', columns: ['code', 'version'])]
class Referential
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** A stable slug shared by every version - `bts-sio`. */
    #[ORM\Column(length: 60)]
    private string $code;

    /** What the screens print - « BTS SIO ». */
    #[ORM\Column(length: 120)]
    private string $label;

    /** A free label of the version, usually the RNCP number or the year it came into force. */
    #[ORM\Column(length: 60)]
    private string $version;

    #[ORM\Column(name: 'rncp_code', length: 20, nullable: true)]
    private ?string $rncpCode = null;

    #[ORM\Column(name: 'rncp_title', type: Types::TEXT, nullable: true)]
    private ?string $rncpTitle = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $level = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $certifier = null;

    #[ORM\Column(name: 'registered_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registeredFrom = null;

    #[ORM\Column(name: 'registered_until', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registeredUntil = null;

    /** The RNCP number of the fiche this one replaces, as the fiche itself states it. */
    #[ORM\Column(name: 'replaces_rncp_code', length: 20, nullable: true)]
    private ?string $replacesRncpCode = null;

    /** The previous version held here, when there is one. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replaces_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $replaces = null;

    /** The date of the open-data export the fiche was read from - Licence Ouverte asks to cite it. */
    #[ORM\Column(name: 'rncp_source_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rncpSourceDate = null;

    #[ORM\Column(name: 'synthesis_model', length: 20, enumType: ReferentialSynthesisModel::class)]
    private ReferentialSynthesisModel $synthesisModel = ReferentialSynthesisModel::Generic;

    /**
     * What the weekly watch (`app:rncp:check`) last read about this fiche in France compétences'
     * export: `checkedAt`, `active`, `registeredUntil`, `replacedBy` (list of RNCP numbers). Null
     * until the first watch, or for a référentiel typed by hand.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'rncp_watch', type: Types::JSON, nullable: true)]
    private ?array $rncpWatch = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, ReferentialBlock> */
    #[ORM\OneToMany(mappedBy: 'referential', targetEntity: ReferentialBlock::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $blocks;

    public function __construct(string $code, string $label, string $version)
    {
        $this->code = $code;
        $this->label = $label;
        $this->version = $version;
        $this->creationDate = new \DateTimeImmutable();
        $this->blocks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

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

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    /** « BTS SIO · RNCP40792 » - what a select or a heading prints. */
    public function getDisplayName(): string
    {
        return $this->label.' · '.$this->version;
    }

    public function getRncpCode(): ?string
    {
        return $this->rncpCode;
    }

    public function setRncpCode(?string $rncpCode): static
    {
        $this->rncpCode = $rncpCode;

        return $this;
    }

    public function getRncpTitle(): ?string
    {
        return $this->rncpTitle;
    }

    public function setRncpTitle(?string $rncpTitle): static
    {
        $this->rncpTitle = $rncpTitle;

        return $this;
    }

    public function getLevel(): ?string
    {
        return $this->level;
    }

    public function setLevel(?string $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getCertifier(): ?string
    {
        return $this->certifier;
    }

    public function setCertifier(?string $certifier): static
    {
        $this->certifier = $certifier;

        return $this;
    }

    public function getRegisteredFrom(): ?\DateTimeImmutable
    {
        return $this->registeredFrom;
    }

    public function setRegisteredFrom(?\DateTimeImmutable $registeredFrom): static
    {
        $this->registeredFrom = $registeredFrom;

        return $this;
    }

    public function getRegisteredUntil(): ?\DateTimeImmutable
    {
        return $this->registeredUntil;
    }

    public function setRegisteredUntil(?\DateTimeImmutable $registeredUntil): static
    {
        $this->registeredUntil = $registeredUntil;

        return $this;
    }

    public function getReplacesRncpCode(): ?string
    {
        return $this->replacesRncpCode;
    }

    public function setReplacesRncpCode(?string $replacesRncpCode): static
    {
        $this->replacesRncpCode = $replacesRncpCode;

        return $this;
    }

    public function getReplaces(): ?self
    {
        return $this->replaces;
    }

    public function setReplaces(?self $replaces): static
    {
        $this->replaces = $replaces;

        return $this;
    }

    public function getRncpSourceDate(): ?\DateTimeImmutable
    {
        return $this->rncpSourceDate;
    }

    public function setRncpSourceDate(?\DateTimeImmutable $rncpSourceDate): static
    {
        $this->rncpSourceDate = $rncpSourceDate;

        return $this;
    }

    public function getSynthesisModel(): ReferentialSynthesisModel
    {
        return $this->synthesisModel;
    }

    public function setSynthesisModel(ReferentialSynthesisModel $synthesisModel): static
    {
        $this->synthesisModel = $synthesisModel;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRncpWatch(): ?array
    {
        return $this->rncpWatch;
    }

    /**
     * @param array<string, mixed>|null $rncpWatch
     */
    public function setRncpWatch(?array $rncpWatch): static
    {
        $this->rncpWatch = $rncpWatch;

        return $this;
    }

    /**
     * What the watch found worth an administrator's attention: the fiche turned inactive, its end
     * of registration moved, or another fiche now replaces it.
     *
     * @return list<string> translation keys
     */
    public function getRncpAlerts(): array
    {
        $watch = $this->rncpWatch;
        if (null === $watch) {
            return [];
        }

        $alerts = [];
        if (false === ($watch['active'] ?? true)) {
            $alerts[] = 'referentialWatchInactiveAlert';
        }
        if (\is_string($watch['registeredUntil'] ?? null) && $watch['registeredUntil'] !== $this->registeredUntil?->format('Y-m-d')) {
            $alerts[] = 'referentialWatchEndChangedAlert';
        }
        if (\is_array($watch['replacedBy'] ?? null) && [] !== $watch['replacedBy']) {
            $alerts[] = 'referentialWatchReplacedAlert';
        }

        return $alerts;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, ReferentialBlock> */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }

    public function addBlock(ReferentialBlock $block): static
    {
        if (!$this->blocks->contains($block)) {
            $this->blocks->add($block);
        }

        return $this;
    }

    public function removeBlock(ReferentialBlock $block): static
    {
        $this->blocks->removeElement($block);

        return $this;
    }

    /**
     * The block the E5 synthesis table is drawn from - one per référentiel, or none yet.
     */
    public function getSynthesisBlock(): ?ReferentialBlock
    {
        foreach ($this->blocks as $block) {
            if (ReferentialBlockRole::Synthesis === $block->getRole()) {
                return $block;
            }
        }

        return null;
    }

    /**
     * The E6 block of an option: a block whose role is Showcase and which names that option. A
     * student with no option has no E6 block - the screen says so rather than guessing one.
     */
    public function getShowcaseBlockFor(?Option $option): ?ReferentialBlock
    {
        if (null === $option) {
            return null;
        }

        foreach ($this->blocks as $block) {
            if (ReferentialBlockRole::Showcase === $block->getRole() && $block->appliesTo($option)) {
                return $block;
            }
        }

        return null;
    }
}
