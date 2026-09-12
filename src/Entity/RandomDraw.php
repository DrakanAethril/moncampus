<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RandomDrawRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named, resumable state of a Program's « Tirage au sort » tool (design/
 * design_handoff_choix_aleatoire_saved) - one teacher's saved draw for one Program.
 *
 * What it carries is the *setting plus the history*: which Option the pool was narrowed to, whether
 * replacement was on, and the ordered list of students already drawn. That last one is the reason
 * the feature exists at all - without replacement, a draw spread over several lessons is nothing
 * but the memory of who has already been called, and a closed browser tab used to erase it.
 *
 * Unlike App\Entity\GroupBatch, which freezes a composition on purpose, $drawnStudentIds is
 * re-resolved against the roster at every read: a student who has left the class between two
 * lessons simply drops out of the list rather than sitting in it as a name nobody can draw.
 *
 * Two draws of one teacher on one Program never carry the same name - a UNIQUE index says so, and
 * App\Service\GroupBatchNaming disambiguates a new one. The banner is nothing but those names.
 */
#[ORM\Entity(repositoryClass: RandomDrawRepository::class)]
#[ORM\Table(name: 'random_draw')]
#[ORM\UniqueConstraint(name: 'uniq_random_draw_program_teacher_name', columns: ['program_id', 'teacher_id', 'name'])]
class RandomDraw
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Program $program = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    // Null means « Toute la classe ». An Option deleted from the structure takes the narrowing with
    // it rather than the whole draw: the history of who was called stays true, only the pool widens.
    #[ORM\ManyToOne(targetEntity: Option::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Option $option = null;

    #[ORM\Column]
    private bool $allowRepeat = false;

    /** @var list<int> */
    #[ORM\Column]
    private array $drawnStudentIds = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Program $program, User $teacher, string $name)
    {
        $this->program = $program;
        $this->teacher = $teacher;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
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

    public function getOption(): ?Option
    {
        return $this->option;
    }

    public function setOption(?Option $option): static
    {
        $this->option = $option;

        return $this;
    }

    public function isAllowRepeat(): bool
    {
        return $this->allowRepeat;
    }

    public function setAllowRepeat(bool $allowRepeat): static
    {
        $this->allowRepeat = $allowRepeat;

        return $this;
    }

    /** @return list<int> */
    public function getDrawnStudentIds(): array
    {
        return $this->drawnStudentIds;
    }

    /**
     * @param array<array-key, int> $drawnStudentIds the ids in the order they were drawn - re-keyed
     *                                               here so the JSON column keeps a list rather
     *                                               than an object once a caller has filtered it
     */
    public function setDrawnStudentIds(array $drawnStudentIds): static
    {
        $this->drawnStudentIds = array_values($drawnStudentIds);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
