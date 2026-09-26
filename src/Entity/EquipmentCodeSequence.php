<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The single row that hands out equipment label numbers - see
 * App\Service\Equipment\EquipmentCodeAllocator.
 *
 * Mapped rather than created by a migration alone so that `doctrine:schema:validate` keeps passing;
 * nothing reads it through the ORM. The row itself is written on first use, so an empty database
 * (the test schema, built from the mapping) needs no seeding.
 */
#[ORM\Entity]
#[ORM\Table(name: 'equipment_code_sequence')]
class EquipmentCodeSequence
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    /** The number the next piece will get. */
    #[ORM\Column(name: 'next_number')]
    private int $nextNumber = 1;

    public function getId(): int
    {
        return $this->id;
    }

    public function getNextNumber(): int
    {
        return $this->nextNumber;
    }
}
