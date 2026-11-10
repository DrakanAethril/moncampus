<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeatingRoomLayoutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How a room of the timetable is furnished, drawn once for every seating plan made in it
 * (design/validated/plans-de-classe.md, §3) - the room is the establishment's, so its disposition
 * is too: whoever may make plans may redraw it, and the last one to do so is named.
 *
 * A plan **copies** it when it starts, and never reads it again: redrawing the room moves no plan
 * already saved.
 */
#[ORM\Entity(repositoryClass: SeatingRoomLayoutRepository::class)]
#[ORM\Table(name: 'seating_room_layout')]
#[ORM\UniqueConstraint(name: 'uniq_seating_room_layout_room', columns: ['room_id'])]
class SeatingRoomLayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Room $room = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $layout = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $layout
     */
    public function __construct(Room $room, array $layout, User $by)
    {
        $this->room = $room;
        $this->layout = $layout;
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        \assert(null !== $this->room);

        return $this->room;
    }

    /** @return array<string, mixed> */
    public function getLayout(): array
    {
        return $this->layout;
    }

    /**
     * @param array<string, mixed> $layout
     */
    public function redraw(array $layout, User $by): static
    {
        $this->layout = $layout;
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
