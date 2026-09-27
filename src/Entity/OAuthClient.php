<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An OAuth client of the Claude connector - in practice one claude.ai or Claude Code installation,
 * registered by itself through dynamic client registration (RFC 7591, App\Controller\OAuth\
 * RegistrationController).
 *
 * **Public, never confidential.** It holds no secret: what proves it is PKCE on every authorisation
 * and a redirect URI drawn from a closed list (App\OAuth\RedirectUriPolicy). Registering is open to
 * anyone on the internet by design - the specification wants it so - which is why a client, on its
 * own, opens nothing: only a user's consent turns one into an App\Entity\OAuthGrant.
 */
#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'oauth_client')]
#[ORM\UniqueConstraint(name: 'uniq_oauth_client_client_id', columns: ['client_id'])]
class OAuthClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'client_id', length: 64)]
    private string $clientId;

    // What the consent screen names, as the client declared it (`client_name`). Displayed escaped
    // and never trusted: it is whatever the registering party chose to write.
    #[ORM\Column(name: 'client_name', length: 200)]
    private string $clientName;

    /** @var list<string> */
    #[ORM\Column(name: 'redirect_uris', type: Types::JSON)]
    private array $redirectUris;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'created_ip', length: 45, nullable: true)]
    private ?string $createdIp;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(string $clientId, string $clientName, array $redirectUris, ?string $createdIp)
    {
        $this->clientId = $clientId;
        $this->clientName = $clientName;
        $this->redirectUris = $redirectUris;
        $this->createdIp = $createdIp;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    /** @return list<string> */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedIp(): ?string
    {
        return $this->createdIp;
    }
}
