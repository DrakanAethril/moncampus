<?php

namespace App\Entity;

use App\Enum\FinancialItemSource;
use App\Enum\FinancialItemType;
use App\Repository\ProgramFinancialItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single line of a Program's financial report - a gain or a cost, computed either per hour of
 * a given LessonType, per student in the program, or from a manually entered quantity. Like
 * LessonSession, this is a line item the user freely adds/removes rather than a
 * structural/reference entity, so it's hard-deleted (no inactiveDate/audit trail).
 */
#[ORM\Entity(repositoryClass: ProgramFinancialItemRepository::class)]
#[ORM\Table(name: 'program_financial_item')]
class ProgramFinancialItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: FinancialItemSource::class)]
    private FinancialItemSource $source;

    #[ORM\Column(length: 20, enumType: FinancialItemType::class)]
    private FinancialItemType $type;

    // Only meaningful for FinancialItemSource::Manual - the other sources derive their own
    // quantity (lesson hours, student count) at report time instead of storing it here.
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $quantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?string $value = null;

    // Only set for FinancialItemSource::Lesson.
    #[ORM\ManyToOne(targetEntity: LessonType::class)]
    #[ORM\JoinColumn(name: 'lesson_type_id', nullable: true)]
    private ?LessonType $lessonType = null;

    #[ORM\ManyToOne(targetEntity: Program::class, inversedBy: 'financialItems')]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    public function __construct(string $title, FinancialItemSource $source, FinancialItemType $type, Program $program)
    {
        $this->title = $title;
        $this->source = $source;
        $this->type = $type;
        $this->setProgram($program);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSource(): FinancialItemSource
    {
        return $this->source;
    }

    public function getType(): FinancialItemType
    {
        return $this->type;
    }

    public function setType(FinancialItemType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getLessonType(): ?LessonType
    {
        return $this->lessonType;
    }

    public function setLessonType(?LessonType $lessonType): static
    {
        $this->lessonType = $lessonType;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        if (null !== $program && !$program->getFinancialItems()->contains($this)) {
            $program->getFinancialItems()->add($this);
        }

        return $this;
    }
}
