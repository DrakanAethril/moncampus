<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferentialBlockRole;
use App\Repository\ReferentialBlockRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One « bloc de compétences » of a référentiel.
 *
 * `options` says which of the establishment's options the block belongs to - empty means the common
 * core. France compétences writes « Option A » and « Option B » in the label; which App\Entity\Option
 * that is here is the administrator's decision at import, never a guess (R14).
 *
 * The RNCP code (`RNCP40792BC01`) is kept for display only: fiches renumber their blocks from one
 * version to the next (SLAM's BC04 became BC03), so two versions are matched by label, never by code.
 */
#[ORM\Entity(repositoryClass: ReferentialBlockRepository::class)]
#[ORM\Table(name: 'referential_block')]
class ReferentialBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Referential::class, inversedBy: 'blocks')]
    #[ORM\JoinColumn(name: 'referential_id', nullable: false, onDelete: 'CASCADE')]
    private ?Referential $referential = null;

    /** The short code the screens use - `B1`, `B2`. */
    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\Column(name: 'rncp_code', length: 30, nullable: true)]
    private ?string $rncpCode = null;

    #[ORM\Column(length: 500)]
    private string $label;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 20, enumType: ReferentialBlockRole::class)]
    private ReferentialBlockRole $role = ReferentialBlockRole::None;

    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'referential_block_option')]
    private Collection $options;

    /** @var Collection<int, ReferentialCompetency> */
    #[ORM\OneToMany(mappedBy: 'block', targetEntity: ReferentialCompetency::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $competencies;

    public function __construct(Referential $referential, string $code, string $label, int $position)
    {
        $this->referential = $referential;
        $this->code = $code;
        $this->label = $label;
        $this->position = $position;
        $this->options = new ArrayCollection();
        $this->competencies = new ArrayCollection();
        $referential->addBlock($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReferential(): ?Referential
    {
        return $this->referential;
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

    public function getRncpCode(): ?string
    {
        return $this->rncpCode;
    }

    public function setRncpCode(?string $rncpCode): static
    {
        $this->rncpCode = $rncpCode;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getRole(): ReferentialBlockRole
    {
        return $this->role;
    }

    public function setRole(ReferentialBlockRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }

    public function isCommonCore(): bool
    {
        return $this->options->isEmpty();
    }

    /** A common-core block applies to everybody; an option block only to that option. */
    public function appliesTo(?Option $option): bool
    {
        if ($this->isCommonCore()) {
            return true;
        }

        return null !== $option && $this->options->exists(static fn (int $key, Option $candidate): bool => $candidate->getId() === $option->getId());
    }

    /** @return Collection<int, ReferentialCompetency> */
    public function getCompetencies(): Collection
    {
        return $this->competencies;
    }

    public function addCompetency(ReferentialCompetency $competency): static
    {
        if (!$this->competencies->contains($competency)) {
            $this->competencies->add($competency);
        }

        return $this;
    }
}
