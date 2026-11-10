<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeatingPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A seating plan - « Outils › Animer la classe › Plans de classe »
 * (design/validated/plans-de-classe.md).
 *
 * **Its author's**, administrators included (App\Security\Voter\SeatingPlanVoter): the colleagues
 * it is shared with read it, print it and duplicate it, and modify it too when $sharedCanEdit says
 * so - sharing and deleting stay the author's.
 *
 * The room, the rules and who sits where are **one JSON document**, read and rewritten whole
 * through App\Service\SeatingPlan\SeatingPlanDocument, the only door. The room is a copy: taking
 * the disposition of a room (App\Entity\SeatingRoomLayout) copies it here, so redrawing the room
 * later moves no saved plan. $revision guards the same plan open in two tabs - or by its author
 * and a colleague who may modify it.
 */
#[ORM\Entity(repositoryClass: SeatingPlanRepository::class)]
#[ORM\Table(name: 'seating_plan')]
#[ORM\Index(name: 'idx_seating_plan_owner_updated', columns: ['owner_id', 'updated_at'])]
class SeatingPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    // A plan places the students of one class: without it there is nobody to place.
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Program $program = null;

    // The timetable's room, when there is one; its name is kept beside it, so a plan whose room
    // was deleted still says where it was drawn for.
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Room $room = null;

    #[ORM\Column(length: 255)]
    private string $roomName = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $document = [];

    #[ORM\Column]
    private int $revision = 1;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'seating_plan_shared_teacher')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $sharedTeachers;

    #[ORM\Column(options: ['default' => false])]
    private bool $sharedCanEdit = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(User $owner, Program $program, string $name, ?Room $room, string $roomName, array $document)
    {
        $this->owner = $owner;
        $this->program = $program;
        $this->name = $name;
        $this->room = $room;
        $this->roomName = $roomName;
        $this->document = $document;
        $this->sharedTeachers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->updatedBy = $owner;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        \assert(null !== $this->owner);

        return $this->owner;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner === $user || (null !== $user->getId() && $this->owner?->getId() === $user->getId());
    }

    public function getProgram(): Program
    {
        \assert(null !== $this->program);

        return $this->program;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function getRoomName(): string
    {
        return $this->roomName;
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

    /** @return array<string, mixed> */
    public function getDocument(): array
    {
        return $this->document;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function replaceDocument(array $document, User $by): static
    {
        $this->document = $document;
        ++$this->revision;
        $this->markModified($by);

        return $this;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    /** @return Collection<int, User> */
    public function getSharedTeachers(): Collection
    {
        return $this->sharedTeachers;
    }

    public function isSharedWith(User $user): bool
    {
        foreach ($this->sharedTeachers as $teacher) {
            if ($teacher === $user || (null !== $user->getId() && $teacher->getId() === $user->getId())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<User> $teachers
     */
    public function shareWith(iterable $teachers, bool $canEdit): static
    {
        $this->sharedTeachers->clear();
        foreach ($teachers as $teacher) {
            if (!$this->isOwnedBy($teacher) && !$this->sharedTeachers->contains($teacher)) {
                $this->sharedTeachers->add($teacher);
            }
        }
        $this->sharedCanEdit = $canEdit;

        return $this;
    }

    public function isSharedCanEdit(): bool
    {
        return $this->sharedCanEdit;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function markModified(User $by): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->updatedBy = $by;

        return $this;
    }
}
