<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EquipmentLocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where spare equipment is stored - a reserve, a cupboard, a bin.
 *
 * Deliberately not a Room: a room is where equipment is *used*, and it already exists in the
 * timetable; a storage place is the inventory's own vocabulary, and a cupboard is not a room anybody
 * schedules.
 */
#[ORM\Entity(repositoryClass: EquipmentLocationRepository::class)]
#[ORM\Table(name: 'equipment_location')]
#[ORM\UniqueConstraint(name: 'uniq_equipment_location_name', columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'equipmentNameAlreadyUsedMessage')]
class EquipmentLocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
