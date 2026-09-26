<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EquipmentStocktakeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A count of Gestion > Matériel - « Comptage d'inventaire »: what is really on the shelves and in
 * the rooms, against what the journal says.
 *
 * One at a time is open. It can be narrowed to a category, so a cupboard can be counted on its own.
 * Closing it is what writes: every piece not found and every quantity that differs becomes an
 * « écart d'inventaire » line (App\Service\Equipment\EquipmentStocktakeCloser), kept apart from the
 * losses somebody declared - the annual report shows the two separately. Abandoning it writes
 * nothing.
 */
#[ORM\Entity(repositoryClass: EquipmentStocktakeRepository::class)]
#[ORM\Table(name: 'equipment_stocktake')]
class EquipmentStocktake
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EquipmentCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EquipmentCategory $category;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'started_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $startedBy;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'closed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $closedBy = null;

    /**
     * Whether the rooms are counted too. Off, only the reserve is compared: a count that did not go
     * round the rooms must not declare every piece in service missing.
     */
    #[ORM\Column(name: 'includes_in_use', options: ['default' => false])]
    private bool $includesInUse;

    /** True when it was abandoned rather than closed: nothing was written. */
    #[ORM\Column(options: ['default' => false])]
    private bool $abandoned = false;

    /** What closing it wrote - « 3 exemplaires manquants, 2 écarts en quantité » - for the history. */
    #[ORM\Column(name: 'shortage_count', options: ['default' => 0])]
    private int $shortageCount = 0;

    #[ORM\Column(name: 'surplus_count', options: ['default' => 0])]
    private int $surplusCount = 0;

    public function __construct(?EquipmentCategory $category, bool $includesInUse, User $startedBy)
    {
        $this->category = $category;
        $this->includesInUse = $includesInUse;
        $this->startedBy = $startedBy;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?EquipmentCategory
    {
        return $this->category;
    }

    public function includesInUse(): bool
    {
        return $this->includesInUse;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getStartedBy(): ?User
    {
        return $this->startedBy;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function isOpen(): bool
    {
        return null === $this->closedAt;
    }

    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }

    public function getShortageCount(): int
    {
        return $this->shortageCount;
    }

    public function getSurplusCount(): int
    {
        return $this->surplusCount;
    }

    public function close(User $by, int $shortages, int $surpluses): void
    {
        $this->closedAt = new \DateTimeImmutable();
        $this->closedBy = $by;
        $this->shortageCount = $shortages;
        $this->surplusCount = $surpluses;
    }

    public function abandon(User $by): void
    {
        $this->closedAt = new \DateTimeImmutable();
        $this->closedBy = $by;
        $this->abandoned = true;
    }
}
