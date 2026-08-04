<?php

namespace App\Entity;

use App\Enum\EmailSuppressionReason;
use App\Repository\EmailSuppressionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une adresse à laquelle la plateforme n'écrit plus, alimentée par les rebonds définitifs et les
 * plaintes remontés par la file « events ».
 *
 * L'enjeu n'est pas l'adresse morte elle-même mais la réputation du domaine : SES suspend l'envoi
 * d'un compte au-delà de 5 % de rebonds, ce qui couperait aussi le mail transactionnel de
 * l'établissement, qui partage la même région. Bloquer en amont est donc une protection de la
 * plateforme entière, pas une commodité d'affichage.
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

    /** Toujours stockée en minuscules : la comparaison doit être insensible à la casse. */
    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 20, enumType: EmailSuppressionReason::class)]
    private EmailSuppressionReason $reason;

    /** Le motif détaillé renvoyé par SES (`diagnosticCode`), pour pouvoir l'expliquer à l'élève. */
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
