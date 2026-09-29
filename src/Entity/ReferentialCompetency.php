<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReferentialCompetencyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One competency of a block, word for word as the fiche writes it - that label is what the synthesis
 * table prints as a column head, and what an uploaded template's columns are matched against.
 *
 * `shortLabel` is the establishment's own abbreviation for the narrow columns of the class view
 * (« Patrim. », « Incid. »); `skills` are the savoir-faire the fiche lists under it (`o …`).
 */
#[ORM\Entity(repositoryClass: ReferentialCompetencyRepository::class)]
#[ORM\Table(name: 'referential_competency')]
class ReferentialCompetency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ReferentialBlock::class, inversedBy: 'competencies')]
    #[ORM\JoinColumn(name: 'block_id', nullable: false, onDelete: 'CASCADE')]
    private ?ReferentialBlock $block = null;

    /** `B1.1` - the block's code, a dot, the rank. */
    #[ORM\Column(length: 20)]
    private string $code;

    #[ORM\Column(length: 500)]
    private string $label;

    #[ORM\Column(name: 'short_label', length: 40, nullable: true)]
    private ?string $shortLabel = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $skills = [];

    #[ORM\Column]
    private int $position = 0;

    /**
     * @param list<string> $skills
     */
    public function __construct(ReferentialBlock $block, string $code, string $label, array $skills, int $position)
    {
        $this->block = $block;
        $this->code = $code;
        $this->label = $label;
        $this->skills = $skills;
        $this->position = $position;
        $block->addCompetency($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlock(): ?ReferentialBlock
    {
        return $this->block;
    }

    public function getCode(): string
    {
        return $this->code;
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

    public function getShortLabel(): ?string
    {
        return $this->shortLabel;
    }

    public function setShortLabel(?string $shortLabel): static
    {
        $this->shortLabel = null === $shortLabel || '' === trim($shortLabel) ? null : trim($shortLabel);

        return $this;
    }

    /** The abbreviation when there is one, else the label itself. */
    public function getDisplayShortLabel(): string
    {
        return $this->shortLabel ?? $this->label;
    }

    /** @return list<string> */
    public function getSkills(): array
    {
        return $this->skills;
    }

    /**
     * @param array<array-key, string> $skills
     */
    public function setSkills(array $skills): static
    {
        $this->skills = array_values($skills);

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
