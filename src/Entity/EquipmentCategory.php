<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EquipmentCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A family of small equipment - Câblage, Périphériques, Audio… - the axis the annual report of
 * Gestion > Matériel groups its losses by.
 */
#[ORM\Entity(repositoryClass: EquipmentCategoryRepository::class)]
#[ORM\Table(name: 'equipment_category')]
#[ORM\UniqueConstraint(name: 'uniq_equipment_category_name', columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'equipmentNameAlreadyUsedMessage')]
class EquipmentCategory
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
