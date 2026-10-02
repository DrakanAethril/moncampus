<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClassBoardBackground;
use App\Repository\ClassBoardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A teacher's virtual board for the projector - « Outils › Animer la classe › Tableau virtuel »
 * (design/validated/tableau-virtuel.md).
 *
 * Personal: only its owner opens, changes or deletes it, administrators included
 * (App\Security\Voter\ClassBoardVoter). Linked to a class or to none; the link is a reading
 * context, never a permission - who may read the class is asked again at every opening.
 *
 * The widgets are one JSON document, read and rewritten whole, never one widget at a time:
 * App\Service\ClassBoard\ClassBoardLayout is the only door the document goes through. What is
 * stored is what the teacher *set* - a timer's duration, a clock's label - never what runs.
 *
 * $revision is the guard against the same board open in two tabs: every write moves it, and a
 * write that started from an older one is refused rather than allowed to erase the other tab's.
 */
#[ORM\Entity(repositoryClass: ClassBoardRepository::class)]
#[ORM\Table(name: 'class_board')]
#[ORM\UniqueConstraint(name: 'uniq_class_board_owner_name', columns: ['owner_id', 'name'])]
class ClassBoard
{
    /** The version of the layout format, so it can move without a data migration. */
    public const int LAYOUT_VERSION = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    // A class deleted from the structure leaves the board standing, unlinked: its Classe widgets
    // then say they have nothing to read rather than taking the rest of the board with them.
    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Program $program = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: ClassBoardBackground::class)]
    private ClassBoardBackground $background = ClassBoardBackground::Slate;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    private array $layout = [];

    #[ORM\Column]
    private int $layoutVersion = self::LAYOUT_VERSION;

    #[ORM\Column]
    private int $revision = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $name, ?Program $program = null)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->program = $program;
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

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        return $this;
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

    public function getBackground(): ClassBoardBackground
    {
        return $this->background;
    }

    public function setBackground(ClassBoardBackground $background): static
    {
        $this->background = $background;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getLayout(): array
    {
        return $this->layout;
    }

    /**
     * Only ever handed a layout App\Service\ClassBoard\ClassBoardLayout has normalised.
     *
     * @param list<array<string, mixed>> $layout
     */
    public function setLayout(array $layout): static
    {
        $this->layout = $layout;
        $this->layoutVersion = self::LAYOUT_VERSION;

        return $this;
    }

    public function getLayoutVersion(): int
    {
        return $this->layoutVersion;
    }

    public function getWidgetCount(): int
    {
        return \count($this->layout);
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Every write of the layout goes through here: the revision moves and « Modifié » follows.
     */
    public function touch(): static
    {
        ++$this->revision;

        return $this->markModified();
    }

    /**
     * A change that leaves the layout alone - a rename, a new class - moves « Modifié » but not the
     * revision: the board open in a tab must not see its next save refused for a name it was not
     * writing.
     */
    public function markModified(): static
    {
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
