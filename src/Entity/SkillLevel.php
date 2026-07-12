<?php

namespace App\Entity;

use App\Repository\SkillLevelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One skill acquisition level shown on the Livret Alternant booklet's competency grid (e.g.
 * "Non évaluable" / "Non acquis" / "En cours d'acquisition" / "Acquis").
 *
 * A null program is the Centre de formation's own definition, managed at
 * SettingsStructureController and used by every Program by default. A Program only gets its own
 * program-scoped levels once it opts into Program::$customSkillLevelsEnabled - see
 * SkillLevelRepository::findAllActiveForProgramOrGlobal(), the single place that decides which
 * set a given Program actually reads. Unlike this entity, SkillGroup/Skill have no shared/opt-out
 * mechanism - they're always Program-owned.
 */
#[ORM\Entity(repositoryClass: SkillLevelRepository::class)]
#[ORM\Table(name: 'skill_level')]
class SkillLevel
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $color;

    #[ORM\Column(name: 'order_index')]
    private int $orderIndex = 0;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: true)]
    private ?Program $program = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label = '', string $color = '#6c757d', ?Program $program = null)
    {
        $this->label = $label;
        $this->color = $color;
        $this->program = $program;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
    }

    public function isGlobal(): bool
    {
        return null === $this->program;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
