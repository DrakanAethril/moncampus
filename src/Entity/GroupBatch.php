<?php

namespace App\Entity;

use App\Repository\GroupBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named, saved snapshot of a Program's "Création de groupes" tool result (design/
 * design_campus_manager/PROMPT_CLAUDE_CODE_groupes.md, section "Lots enregistrés") - one teacher's
 * "lot" for one Program. $groups is a fixed snapshot at save time (a plain list of lists of
 * student ids, one inner list per group, in group order) - unlike App\Entity\MessageThread's
 * Program-audience fan-out, it deliberately does NOT re-resolve membership later: a student who
 * joins/leaves the Program after a lot was saved must not silently change who's in it.
 */
#[ORM\Entity(repositoryClass: GroupBatchRepository::class)]
#[ORM\Table(name: 'group_batch')]
class GroupBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Program $program = null;

    // The saving teacher - lots are scoped per teacher×Program, not shared across the whole
    // teaching team (design's "Persistance en BDD (professeur × classe)").
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    // Explicitly backtick-quoted name - "groups" is a MySQL 8 reserved word (the GROUPS window
    // frame unit), and without this Doctrine only quotes it in the migration's own CREATE TABLE,
    // not in the runtime INSERT/UPDATE it generates for persist()/flush(), which fails outright.
    /** @var list<list<int>> */
    #[ORM\Column(name: '`groups`')]
    private array $groups = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Program $program, User $teacher, string $name, array $groups)
    {
        $this->program = $program;
        $this->teacher = $teacher;
        $this->name = $name;
        $this->groups = $groups;
        $this->createdAt = new \DateTimeImmutable();
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

    /** @return list<list<int>> */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /** @param list<list<int>> $groups */
    public function setGroups(array $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
