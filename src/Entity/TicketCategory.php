<?php

namespace App\Entity;

use App\Repository\TicketCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A ticket classification (e.g. "Network/WiFi", "Equipment", "Facilities") - settings-managed
 * like Room/LessonType so handlers can add/retire categories without a code change.
 */
#[ORM\Entity(repositoryClass: TicketCategoryRepository::class)]
#[ORM\Table(name: 'ticket_category')]
class TicketCategory
{
    use AuditableTrait;

    // Seeded by a migration and looked up by PublicTicketController - the one category the
    // logged-out "lost access" form always assigns, since it has no category picker of its own.
    // A single named constant keeps the seed migration and the lookup in sync.
    public const string ACCOUNT_ACCESS_NAME = 'Accès au compte / connexion';

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
