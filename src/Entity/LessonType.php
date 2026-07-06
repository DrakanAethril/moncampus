<?php

namespace App\Entity;

use App\Repository\LessonTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A kind of lesson session (e.g. Cours, TD, TP), each with its own agenda color so the
 * weekly timetable is visually distinguishable at a glance.
 */
#[ORM\Entity(repositoryClass: LessonTypeRepository::class)]
#[ORM\Table(name: 'lesson_type')]
class LessonType
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(name: 'agenda_color', length: 20)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    private string $agendaColor;

    // The structure-wide default hourly cost for this kind of session (e.g. a teacher's hourly
    // rate for a Cours vs a TD) - used by financial items unless a program overrides it via its
    // own ProgramLessonTypeCost row.
    #[ORM\Column(name: 'default_cost', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $defaultCost = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name, string $agendaColor)
    {
        $this->name = $name;
        $this->agendaColor = $agendaColor;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getAgendaColor(): string
    {
        return $this->agendaColor;
    }

    public function setAgendaColor(string $agendaColor): static
    {
        $this->agendaColor = $agendaColor;

        return $this;
    }

    public function getDefaultCost(): ?string
    {
        return $this->defaultCost;
    }

    public function setDefaultCost(?string $defaultCost): static
    {
        $this->defaultCost = $defaultCost;

        return $this;
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
