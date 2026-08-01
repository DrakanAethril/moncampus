<?php

namespace App\Entity;

use App\Enum\PlatformActivityType;
use App\Repository\PlatformActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal horodaté de la plateforme - tout ce qui n'est pas l'UFA, à commencer par les connexions.
 * Aucune clé étrangère vers l'UFA : la séparation d'avec App\Entity\UfaActivity est le point même
 * de cette table, et elle tient parce que rien dans le schéma ne les relie.
 *
 * Mêmes règles qu'UfaActivity : ajout seul, écriture après le flush métier, phrase composée à
 * l'affichage depuis $type et $payload.
 *
 * $ipAddress/$userAgent n'existent que de ce côté : c'est ce journal-ci qu'on ouvrira le jour où
 * l'on demandera d'où quelqu'un s'est connecté.
 *
 * Contrairement à UfaActivity, purgé au-delà de 12 mois (App\Command\PurgePlatformActivityCommand) :
 * une ligne par connexion, c'est la table qui grossit.
 */
#[ORM\Entity(repositoryClass: PlatformActivityRepository::class)]
#[ORM\Table(name: 'platform_activity')]
#[ORM\Index(name: 'idx_platform_activity_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_platform_activity_actor', columns: ['actor_id', 'occurred_at'])]
class PlatformActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 60, enumType: PlatformActivityType::class)]
    private PlatformActivityType $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    // 45 caractères : la longueur d'une IPv6 en notation complète.
    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /** @param array<string, string> $payload */
    public function __construct(PlatformActivityType $type, ?User $actor, array $payload = [])
    {
        $this->type = $type;
        $this->actor = $actor;
        $this->payload = $payload;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getType(): PlatformActivityType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        // Tronqué plutôt que refusé : un User-Agent exotique ne doit pas faire échouer une
        // connexion par ailleurs valide.
        $this->userAgent = null !== $userAgent ? mb_substr($userAgent, 0, 255) : null;

        return $this;
    }

    /** @return array<string, string> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
