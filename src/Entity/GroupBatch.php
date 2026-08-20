<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupBatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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
 *
 * A lot belongs to the teacher who saved it, and $sharedTeachers is the list of colleagues they
 * have opened it to - read-only for those colleagues, who see it under "Groupes partagés avec moi"
 * and may load it, but never rename, re-share or delete it.
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

    // The saving teacher, and the owner: lots are scoped per teacher×Program (design's
    // "Persistance en BDD (professeur × classe)"), and only $sharedTeachers widens who reads one.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $teacher = null;

    // Colleagues this lot is shared with - a bare join table rather than an App\Entity\ContentShare
    // row (the way progression_co_teacher does it), because the link here is a permission and not an
    // authoring act: no note, no revocation date, no catalog. Sharing to nobody is a legitimate
    // state, and un-sharing is simply removing the row, so there is nothing to keep a history of.
    // Candidates are always taken from the Program's own teachers, never from the whole directory.
    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'group_batch_shared_teacher')]
    #[ORM\JoinColumn(name: 'group_batch_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'teacher_id', onDelete: 'CASCADE')]
    private Collection $sharedTeachers;

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
        $this->sharedTeachers = new ArrayCollection();
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

    /** @return Collection<int, User> */
    public function getSharedTeachers(): Collection
    {
        return $this->sharedTeachers;
    }

    public function isSharedWith(User $teacher): bool
    {
        return $this->sharedTeachers->contains($teacher);
    }

    public function addSharedTeacher(User $teacher): static
    {
        // The owner is never one of their own recipients - the lot already sits in their "Mes
        // groupes", and letting the row exist would show it twice on their own screen.
        if ($teacher !== $this->teacher && !$this->sharedTeachers->contains($teacher)) {
            $this->sharedTeachers->add($teacher);
        }

        return $this;
    }

    public function removeSharedTeacher(User $teacher): static
    {
        $this->sharedTeachers->removeElement($teacher);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
