<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EmailSuppressionReason;
use App\Repository\EmailSuppressionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An address the platform no longer writes to, fed by the permanent bounces and the complaints
 * reported through the « events » queue.
 *
 * What is at stake is not the dead address itself but the domain's reputation: SES suspends an
 * account's sending beyond 5 % of bounces, which would also cut the school's transactional mail,
 * sharing the same region. Blocking upstream is therefore a protection of the whole platform, not a
 * display convenience.
 */
#[ORM\Entity(repositoryClass: EmailSuppressionRepository::class)]
#[ORM\Table(name: 'email_suppression')]
#[ORM\UniqueConstraint(name: 'uniq_email_suppression_address', columns: ['address'])]
class EmailSuppression
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Always stored in lower case: the comparison must be case-insensitive. */
    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 20, enumType: EmailSuppressionReason::class)]
    private EmailSuppressionReason $reason;

    /** The detailed reason returned by SES (`diagnosticCode`), so it can be explained to the student. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = mb_strtolower(trim($address));

        return $this;
    }

    public function getReason(): EmailSuppressionReason
    {
        return $this->reason;
    }

    public function setReason(EmailSuppressionReason $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
