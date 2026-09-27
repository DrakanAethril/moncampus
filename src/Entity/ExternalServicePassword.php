<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExternalService;
use App\Repository\ExternalServicePasswordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The password somebody chose for one external service (App\Enum\ExternalService) - what that
 * service's sign-in asks for instead of the establishment password.
 *
 * Only its hash is stored (App\Security\ExternalServicePasswords), like everything this platform
 * keeps of a secret: it cannot be read back, only replaced. One per person and per service.
 */
#[ORM\Entity(repositoryClass: ExternalServicePasswordRepository::class)]
#[ORM\Table(name: 'external_service_password')]
#[ORM\UniqueConstraint(name: 'uniq_external_service_password_user_service', columns: ['user_id', 'service'])]
class ExternalServicePassword
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 40, enumType: ExternalService::class)]
    private ExternalService $service;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(User $user, ExternalService $service, string $passwordHash, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->service = $service;
        $this->passwordHash = $passwordHash;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getService(): ExternalService
    {
        return $this->service;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function replace(string $passwordHash, \DateTimeImmutable $now): static
    {
        $this->passwordHash = $passwordHash;
        $this->updatedAt = $now;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(\DateTimeImmutable $now): static
    {
        $this->lastUsedAt = $now;

        return $this;
    }
}
