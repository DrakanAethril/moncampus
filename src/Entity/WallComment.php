<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WallCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A comment under a card of a collaborative wall - only ever written while the wall's
 * « Commentaires » switch is on. Switching it off hides the comments; it deletes none.
 */
#[ORM\Entity(repositoryClass: WallCommentRepository::class)]
#[ORM\Table(name: 'wall_comment')]
class WallComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WallCard::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WallCard $card = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(WallCard $card, User $author, string $text)
    {
        $this->card = $card;
        $this->author = $author;
        $this->text = $text;
        $this->createdAt = new \DateTimeImmutable();
        $card->getComments()->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCard(): WallCard
    {
        \assert(null !== $this->card);

        return $this->card;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function isWrittenBy(User $user): bool
    {
        return $this->author === $user;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
