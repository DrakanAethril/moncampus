<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentationCounterResetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One campus-wide reset of the "depuis la remise à zéro" read counters (handoff 2f).
 *
 * A row per reset rather than a single mutable date: the dashboard only ever shows the last one,
 * but a counter that lost 12 480 readings should be able to say when, and by whom, more than once
 * in the life of the base. The reset itself is global by design - there is no partial scope.
 */
#[ORM\Entity(repositoryClass: DocumentationCounterResetRepository::class)]
#[ORM\Table(name: 'documentation_counter_reset')]
class DocumentationCounterReset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'reset_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $resetAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reset_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $resetBy = null;

    // What the campus-wide "depuis la remise à zéro" total was worth just before it was zeroed.
    #[ORM\Column(name: 'cleared_total')]
    private int $clearedTotal;

    public function __construct(?User $resetBy, int $clearedTotal)
    {
        $this->resetBy = $resetBy;
        $this->clearedTotal = $clearedTotal;
        $this->resetAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResetAt(): \DateTimeImmutable
    {
        return $this->resetAt;
    }

    public function getResetBy(): ?User
    {
        return $this->resetBy;
    }

    public function getClearedTotal(): int
    {
        return $this->clearedTotal;
    }
}
