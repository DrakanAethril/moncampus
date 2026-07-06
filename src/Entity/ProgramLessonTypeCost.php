<?php

namespace App\Entity;

use App\Repository\ProgramLessonTypeCostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A program-specific override of a LessonType's default hourly cost (e.g. this program pays its
 * teachers a different TD rate than the structure-wide default). Presence of a row IS the
 * override - there's no "use default" flag, the row is simply deleted to fall back to
 * LessonType::getDefaultCost().
 */
#[ORM\Entity(repositoryClass: ProgramLessonTypeCostRepository::class)]
#[ORM\Table(name: 'program_lesson_type_cost')]
#[ORM\UniqueConstraint(name: 'program_lesson_type_unique', columns: ['program_id', 'lesson_type_id'])]
class ProgramLessonTypeCost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: LessonType::class)]
    #[ORM\JoinColumn(name: 'lesson_type_id', nullable: false)]
    #[Assert\NotNull]
    private ?LessonType $lessonType = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?string $cost = null;

    public function __construct(Program $program, LessonType $lessonType, string $cost)
    {
        $this->program = $program;
        $this->lessonType = $lessonType;
        $this->cost = $cost;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function getLessonType(): ?LessonType
    {
        return $this->lessonType;
    }

    public function getCost(): ?string
    {
        return $this->cost;
    }

    public function setCost(string $cost): static
    {
        $this->cost = $cost;

        return $this;
    }
}
