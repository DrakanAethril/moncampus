<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WallFormat;
use App\Repository\WallRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A collaborative wall - « Murs collaboratifs » (design/design_handoff_murs_collaboratifs): lists
 * holding cards, a Trello and a Padlet in one. Named « mur » because « tableau » was taken by the
 * virtual board (App\Entity\ClassBoard), which is a different tool.
 *
 * A wall is its owner's. It is opened to others in two ways, and both are the owner's gesture
 * alone: named people ($members) and whole classes ($programs). What each of them may then do is
 * App\Service\Wall\WallAccess's business and nothing else's - nothing here reads a role.
 *
 * The settings of the handoff's « Paramètres du mur » panel are columns of this row rather than a
 * JSON document: seven booleans and a colour, each read on its own by the rule or by the screen.
 *
 * $revision moves with every change to the wall, its lists, its cards or its comments
 * (touch()): it is what a browser watching the wall compares to know it is behind.
 */
#[ORM\Entity(repositoryClass: WallRepository::class)]
#[ORM\Table(name: 'wall')]
class Wall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 20, enumType: WallFormat::class)]
    private WallFormat $format;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'wall_member')]
    private Collection $members;

    /** @var Collection<int, Program> */
    #[ORM\ManyToMany(targetEntity: Program::class)]
    #[ORM\JoinTable(name: 'wall_program')]
    private Collection $programs;

    /** @var Collection<int, WallList> */
    #[ORM\OneToMany(mappedBy: 'wall', targetEntity: WallList::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lists;

    // « Fond »: a colour or an imported picture, never both - choosing one clears the other.
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $backgroundColor = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $backgroundImageKey = null;

    // « Affichage ».
    #[ORM\Column]
    private bool $authorsShown = true;

    #[ORM\Column]
    private bool $labelsShown = true;

    #[ORM\Column]
    private bool $countsShown = true;

    // « Participation ». Comments are the one switch that starts off.
    #[ORM\Column]
    private bool $commentsEnabled = false;

    #[ORM\Column]
    private bool $participantsMayAdd = true;

    #[ORM\Column]
    private bool $participantsMayEditOthers = false;

    #[ORM\Column]
    private bool $moderated = false;

    #[ORM\Column]
    private int $revision = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $title, WallFormat $format)
    {
        $this->owner = $owner;
        $this->title = $title;
        $this->format = $format;
        $this->members = new ArrayCollection();
        $this->programs = new ArrayCollection();
        $this->lists = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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
        return $this->owner === $user;
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

    public function getFormat(): WallFormat
    {
        return $this->format;
    }

    public function setFormat(WallFormat $format): static
    {
        $this->format = $format;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(User $member): static
    {
        if (!$this->isOwnedBy($member) && !$this->members->contains($member)) {
            $this->members->add($member);
        }

        return $this;
    }

    public function removeMember(User $member): static
    {
        $this->members->removeElement($member);

        return $this;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
    }

    public function addProgram(Program $program): static
    {
        if (!$this->programs->contains($program)) {
            $this->programs->add($program);
        }

        return $this;
    }

    public function removeProgram(Program $program): static
    {
        $this->programs->removeElement($program);

        return $this;
    }

    public function isShared(): bool
    {
        return !$this->members->isEmpty() || !$this->programs->isEmpty();
    }

    /** @return Collection<int, WallList> */
    public function getLists(): Collection
    {
        return $this->lists;
    }

    public function addList(WallList $list): static
    {
        if (!$this->lists->contains($list)) {
            $this->lists->add($list);
        }

        return $this;
    }

    public function removeList(WallList $list): static
    {
        $this->lists->removeElement($list);

        return $this;
    }

    public function getBackgroundColor(): ?string
    {
        return $this->backgroundColor;
    }

    public function getBackgroundImageKey(): ?string
    {
        return $this->backgroundImageKey;
    }

    public function setBackgroundColor(?string $color): static
    {
        $this->backgroundColor = $color;
        $this->backgroundImageKey = null;

        return $this;
    }

    public function setBackgroundImageKey(?string $key): static
    {
        $this->backgroundImageKey = $key;
        if (null !== $key) {
            $this->backgroundColor = null;
        }

        return $this;
    }

    public function areAuthorsShown(): bool
    {
        return $this->authorsShown;
    }

    public function setAuthorsShown(bool $shown): static
    {
        $this->authorsShown = $shown;

        return $this;
    }

    public function areLabelsShown(): bool
    {
        return $this->labelsShown;
    }

    public function setLabelsShown(bool $shown): static
    {
        $this->labelsShown = $shown;

        return $this;
    }

    public function areCountsShown(): bool
    {
        return $this->countsShown;
    }

    public function setCountsShown(bool $shown): static
    {
        $this->countsShown = $shown;

        return $this;
    }

    public function areCommentsEnabled(): bool
    {
        return $this->commentsEnabled;
    }

    public function setCommentsEnabled(bool $enabled): static
    {
        $this->commentsEnabled = $enabled;

        return $this;
    }

    public function mayParticipantsAdd(): bool
    {
        return $this->participantsMayAdd;
    }

    public function setParticipantsMayAdd(bool $allowed): static
    {
        $this->participantsMayAdd = $allowed;

        return $this;
    }

    public function mayParticipantsEditOthers(): bool
    {
        return $this->participantsMayEditOthers;
    }

    public function setParticipantsMayEditOthers(bool $allowed): static
    {
        $this->participantsMayEditOthers = $allowed;

        return $this;
    }

    public function isModerated(): bool
    {
        return $this->moderated;
    }

    public function setModerated(bool $moderated): static
    {
        $this->moderated = $moderated;

        return $this;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    /** Every write to the wall or to anything on it goes through here. */
    public function touch(): static
    {
        ++$this->revision;
        $this->updatedAt = new \DateTimeImmutable();

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
}
