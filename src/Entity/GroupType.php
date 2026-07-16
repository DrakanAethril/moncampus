<?php

namespace App\Entity;

use App\Repository\GroupTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Purely a display grouping for App\Entity\Group (LDAP-mirrored or local-only alike) - e.g.
 * "Transversaux", "BTS SIO", "Bac+3 Info" - so screens that list many groups as checkboxes/chips
 * (see the secondary-groups picker on the user creation form) can cluster them under a heading
 * instead of one flat list. Optional and purely cosmetic: nothing about role resolution or LDAP
 * sync reads this - a Group with no GroupType still works exactly as before, it just renders
 * under a generic "Autres" bucket on those screens.
 */
#[ORM\Entity(repositoryClass: GroupTypeRepository::class)]
#[ORM\Table(name: 'group_type')]
#[UniqueEntity(fields: ['name'], message: 'groupTypeNameAlreadyUsedMessage')]
class GroupType
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name)
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

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
