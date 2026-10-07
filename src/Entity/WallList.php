<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WallListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One list of a collaborative wall: a column in « Colonnes », the origin a card names in « Grille ».
 *
 * $color is null for the default grey, so that the default follows the reader's theme; a chosen
 * colour is the list's own and is drawn as chosen whatever the theme.
 */
#[ORM\Entity(repositoryClass: WallListRepository::class)]
#[ORM\Table(name: 'wall_list')]
class WallList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Wall::class, inversedBy: 'lists')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Wall $wall = null;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, WallCard> */
    #[ORM\OneToMany(mappedBy: 'list', targetEntity: WallCard::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $cards;

    public function __construct(Wall $wall, string $title, int $position = 0)
    {
        $this->wall = $wall;
        $this->title = $title;
        $this->position = $position;
        $this->cards = new ArrayCollection();
        $wall->addList($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWall(): Wall
    {
        \assert(null !== $this->wall);

        return $this->wall;
    }

    /** Only ever called by a move from one wall to another (App\Service\Wall\WallCopier). */
    public function setWall(Wall $wall): static
    {
        $this->wall?->removeList($this);
        $this->wall = $wall;
        $wall->addList($this);

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

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
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

    /** @return Collection<int, WallCard> */
    public function getCards(): Collection
    {
        return $this->cards;
    }

    public function addCard(WallCard $card): static
    {
        if (!$this->cards->contains($card)) {
            $this->cards->add($card);
        }

        return $this;
    }

    public function removeCard(WallCard $card): static
    {
        $this->cards->removeElement($card);

        return $this;
    }
}
