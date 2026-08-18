<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Enum\ProxmoxCredentialKind;

/**
 * One decrypted credential set, ready to authenticate with - either the everyday "operate" account
 * or the "provision" one. Built only by App\Service\Proxmox\ProxmoxClientFactory, which is the sole
 * place in the repository that opens a sealed secret.
 *
 * Readonly and short-lived on purpose: it exists for the duration of one client, is never persisted,
 * never serialised, and never reaches a template.
 */
final readonly class ProxmoxCredentials
{
    public function __construct(
        public ProxmoxCredentialKind $kind,
        public string $username,
        public string $realm,
        public ?string $tokenName,
        #[\SensitiveParameter] public string $secret,
    ) {
    }

    /** `svc-moncampus@pve` - what both the ticket call and the token header start from. */
    public function userId(): string
    {
        return \sprintf('%s@%s', $this->username, $this->realm);
    }

    /**
     * The Authorization header value of an API token: `PVEAPIToken=user@realm!tokenid=secret`.
     * Never built for a password credential - the caller checks the kind first.
     */
    public function authorizationHeader(): string
    {
        return \sprintf('PVEAPIToken=%s!%s=%s', $this->userId(), $this->tokenName ?? '', $this->secret);
    }

    /**
     * Identifies this credential set without revealing it, for the ticket cache key. Changing the
     * secret changes the digest, which invalidates the cached ticket on its own - there is no purge
     * code to remember to call.
     */
    public function digest(): string
    {
        return substr(hash('sha256', $this->userId().'|'.($this->tokenName ?? '').'|'.$this->secret), 0, 32);
    }

    /** Keeps the secret out of dumps and out of the traces Monolog ships to Discord. */
    public function __debugInfo(): array
    {
        return [
            'kind' => $this->kind,
            'username' => $this->username,
            'realm' => $this->realm,
            'tokenName' => $this->tokenName,
            'secret' => '***',
        ];
    }
}
