<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\WallCardStatus;
use App\Enum\WallLabelTone;
use App\Repository\WallCardRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A card of a collaborative wall: a title, and any of a label, a text, a picture, a link, a file
 * and a checklist - at most one of each.
 *
 * The picture and the file are objects of the uploads bucket under `walls/` (App\Service\Wall\WallFiles);
 * a copied card owns copies of them, never the same keys, so that deleting one card can never take
 * another's file with it.
 *
 * The checklist is a JSON list whose items carry an id of their own: two people ticking the same
 * list at the same moment name the item they mean, not a rank that the other has just shifted.
 *
 * The author survives as null when their account is removed - the card belongs to the wall.
 *
 * @phpstan-type ChecklistItem array{id: string, text: string, done: bool}
 */
#[ORM\Entity(repositoryClass: WallCardRepository::class)]
#[ORM\Table(name: 'wall_card')]
class WallCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WallList::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WallList $list = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 10, nullable: true, enumType: WallLabelTone::class)]
    private ?WallLabelTone $labelTone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $linkTitle = null;

    #[ORM\Column(length: 2000, nullable: true)]
    private ?string $linkUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    /** @var list<ChecklistItem> */
    #[ORM\Column(type: Types::JSON)]
    private array $checklist = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(length: 20, enumType: WallCardStatus::class)]
    private WallCardStatus $status = WallCardStatus::Published;

    /** @var Collection<int, WallComment> */
    #[ORM\OneToMany(mappedBy: 'card', targetEntity: WallComment::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $comments;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(WallList $list, string $title, ?User $author)
    {
        $this->list = $list;
        $this->title = $title;
        $this->author = $author;
        $this->comments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $list->addCard($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getList(): WallList
    {
        \assert(null !== $this->list);

        return $this->list;
    }

    public function setList(WallList $list): static
    {
        if ($this->list !== $list) {
            $this->list?->removeCard($this);
            $this->list = $list;
            $list->addCard($this);
        }

        return $this;
    }

    public function getWall(): Wall
    {
        return $this->getList()->getWall();
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getLabelTone(): ?WallLabelTone
    {
        return $this->labelTone;
    }

    /** A label is its text and its colour together; removing it removes both. */
    public function setLabel(?string $label, ?WallLabelTone $tone = null): static
    {
        $this->label = $label;
        $this->labelTone = null === $label ? null : ($tone ?? WallLabelTone::Grey);

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getImageKey(): ?string
    {
        return $this->imageKey;
    }

    public function setImageKey(?string $imageKey): static
    {
        $this->imageKey = $imageKey;

        return $this;
    }

    public function getLinkTitle(): ?string
    {
        return $this->linkTitle;
    }

    public function getLinkUrl(): ?string
    {
        return $this->linkUrl;
    }

    public function setLink(?string $url, ?string $title = null): static
    {
        $this->linkUrl = $url;
        $this->linkTitle = null === $url ? null : $title;

        return $this;
    }

    public function getFileKey(): ?string
    {
        return $this->fileKey;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getFileExtension(): string
    {
        return mb_strtoupper(pathinfo((string) $this->fileName, \PATHINFO_EXTENSION));
    }

    public function setFile(?string $key, ?string $name = null, ?int $size = null): static
    {
        $this->fileKey = $key;
        $this->fileName = null === $key ? null : $name;
        $this->fileSize = null === $key ? null : $size;

        return $this;
    }

    /** @return list<ChecklistItem> */
    public function getChecklist(): array
    {
        return $this->checklist;
    }

    /** @param array<array-key, ChecklistItem> $checklist */
    public function setChecklist(array $checklist): static
    {
        $this->checklist = array_values($checklist);

        return $this;
    }

    public function getChecklistDoneCount(): int
    {
        return \count(array_filter($this->checklist, static fn (array $item): bool => $item['done']));
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function isWrittenBy(User $user): bool
    {
        return $this->author === $user;
    }

    public function getStatus(): WallCardStatus
    {
        return $this->status;
    }

    public function setStatus(WallCardStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isPending(): bool
    {
        return WallCardStatus::Pending === $this->status;
    }

    /** @return Collection<int, WallComment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
