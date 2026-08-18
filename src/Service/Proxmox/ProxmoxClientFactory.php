<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\ProxmoxHost;
use App\Enum\ProxmoxCredentialKind;
use App\Enum\ProxmoxTlsMode;
use App\Service\Crypto\SecretBoxException;
use App\Service\Crypto\SecretBoxProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds a App\Service\Proxmox\ProxmoxClient for a host, and picks which of its two credential sets
 * to use.
 *
 * **This is the only place in the repository that decrypts a Proxmox secret.** Nothing else calls
 * SecretBox::open() on these columns, no getter on the entity returns plaintext, and no route
 * renders one. Keeping the single decryption point findable is what makes "the secret is never
 * visible again" checkable rather than merely intended.
 *
 * The two entry points are named after the two Proxmox service accounts of the design:
 * operate() for the everyday account (list, start, stop, read configs) and provision() for the
 * separate one carrying VM.Allocate. A host with no provisioning secret has no provision client at
 * all - the method throws rather than quietly falling back to the operating account, because
 * falling back is precisely what would hand VM.Allocate back to the everyday credentials.
 */
class ProxmoxClientFactory
{
    public function __construct(
        private readonly HttpClientInterface $proxmoxHttpClient,
        private readonly CacheItemPoolInterface $proxmoxTicketCache,
        private readonly SecretBoxProvider $secretBoxProvider,
        private readonly ProxmoxTlsOptions $tlsOptions,
    ) {
    }

    /** @throws ProxmoxUnavailableException when the host has no usable operating credentials */
    public function operate(ProxmoxHost $host): ProxmoxClient
    {
        if (!$host->hasSecret()) {
            throw new ProxmoxUnavailableException(\sprintf('Host "%s" has no stored secret.', $host->getLabel()));
        }

        $credentials = new ProxmoxCredentials(
            $host->getCredentialKind(),
            $host->getUsername(),
            $host->getRealm(),
            $host->getTokenName(),
            $this->open($host->getSecretCipher()),
        );

        return $this->build($host, $credentials, 'operate');
    }

    /** @throws ProxmoxUnavailableException when the host carries no second credential set */
    public function provision(ProxmoxHost $host): ProxmoxClient
    {
        $cipher = $host->getProvisionSecretCipher();
        $username = $host->getProvisionUsername();

        if (null === $cipher || '' === $cipher || null === $username || '' === $username) {
            throw new ProxmoxUnavailableException(\sprintf('Host "%s" has no provisioning credentials: creating machines is unavailable on it.', $host->getLabel()));
        }

        $credentials = new ProxmoxCredentials(
            $host->getCredentialKind(),
            $username,
            $host->getProvisionRealm() ?? $host->getRealm(),
            $host->getProvisionTokenName(),
            $this->open($cipher),
        );

        return $this->build($host, $credentials, 'provision');
    }

    /**
     * A client built from values that are not saved yet - what "Tester la connexion" runs on the
     * host form. Without it the only way to test a declaration would be to store it first, which
     * leaves a broken row in the database every time somebody mistypes a password.
     *
     * @throws ProxmoxUnavailableException when the chosen TLS mode lacks its CA or its pin
     */
    public function draft(
        string $hostname,
        int $port,
        ProxmoxCredentialKind $kind,
        string $username,
        string $realm,
        ?string $tokenName,
        #[\SensitiveParameter] string $secret,
        ProxmoxTlsMode $tlsMode,
        ?string $caPem,
        ?string $pinSha256,
    ): ProxmoxClient {
        $credentials = new ProxmoxCredentials($kind, $username, $realm, $tokenName, $secret);

        return new ProxmoxClient(
            $this->proxmoxHttpClient,
            $this->proxmoxTicketCache,
            \sprintf('https://%s:%d', $hostname, $port),
            $credentials,
            $this->tlsOptions->build($tlsMode, $caPem, $pinSha256),
            \sprintf('draft.%s', $credentials->digest()),
        );
    }

    private function build(ProxmoxHost $host, ProxmoxCredentials $credentials, string $role): ProxmoxClient
    {
        return new ProxmoxClient(
            $this->proxmoxHttpClient,
            $this->proxmoxTicketCache,
            $host->getBaseUrl(),
            $credentials,
            $this->tlsOptions->build($host->getTlsMode(), $host->getTlsCaPem(), $host->getTlsPinSha256()),
            \sprintf('host.%d.%s.%s', $host->getId() ?? 0, $role, $credentials->digest()),
        );
    }

    private function open(string $cipher): string
    {
        try {
            return $this->secretBoxProvider->get()->open($cipher);
        } catch (SecretBoxException $exception) {
            // Deliberately reworded: the caller shows this to an administrator, and "the key is
            // wrong" is the actionable half of any of SecretBox's failures here.
            throw new ProxmoxUnavailableException('The stored secret could not be decrypted - check PROXMOX_SECRET_KEY.', previous: $exception);
        }
    }
}
