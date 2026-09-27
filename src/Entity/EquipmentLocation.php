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
 * Deliberately not a Room: a cupboard is not a room anybody schedules, so the inventory names it in
 * its own vocabulary. A room *can* still be where spares are kept - the « Emplacement » field offers
 * the platform's rooms next to these (EquipmentType/EquipmentItem::setPlace()) - which is why this
 * list only ever needs the places that are not rooms.
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
