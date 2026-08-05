<?php

namespace App\Entity;

use App\Enum\EmailSuppressionReason;
use App\Repository\SuppressedEmailAddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An address the platform has stopped writing to (design_handoff_courrier_ecole_infra §6, "hard
 * bounce -> liste de suppression locale").
 *
 * The reason is the domain's reputation, not tidiness: every mail sent to a dead address, and every
 * mail sent to somebody who marked us as spam, counts against the sending domain at SES. Enough of
 * them and the whole school stops being able to write to anyone.
 *
 * Local rather than relying on SES's own suppression list, because the student has to be told *now*,
 * while they are typing the address, rather than discover a week later that nothing ever left.
 */
#[ORM\Entity(repositoryClass: SuppressedEmailAddressRepository::class)]
#[ORM\Table(name: 'suppressed_email_address')]
#[ORM\UniqueConstraint(name: 'uniq_suppressed_email_address', columns: ['address'])]
class SuppressedEmailAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 20, enumType: EmailSuppressionReason::class)]
    private EmailSuppressionReason $reason;

    /** The bounce or complaint sub-type SES reported, kept verbatim for whoever investigates later. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $detail = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $address, EmailSuppressionReason $reason, ?string $detail = null)
    {
        $this->address = mb_strtolower(trim($address));
        $this->reason = $reason;
        $this->detail = $detail;
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

    public function getReason(): EmailSuppressionReason
    {
        return $this->reason;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
