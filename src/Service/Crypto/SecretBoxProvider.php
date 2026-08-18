<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Builds App\Service\Crypto\SecretBox lazily, so that a missing or badly sized PROXMOX_SECRET_KEY
 * is a *state the screens can render* rather than a container error.
 *
 * SecretBox refuses to exist without a usable key - deliberately, see its docblock. Autowiring it
 * straight into a controller would therefore turn a blank key into a 500 with no usable message,
 * which is exactly the silent-misconfiguration shape `app:antivirus:check` exists to prevent for
 * uploads. Everything on the Proxmox side asks this provider instead: the hosts screen shows an
 * "encryption unavailable" banner, and the client factory raises a
 * App\Service\Proxmox\ProxmoxUnavailableException a caller already handles.
 */
class SecretBoxProvider
{
    private ?SecretBox $secretBox = null;

    private ?string $failure = null;

    private bool $attempted = false;

    public function __construct(#[\SensitiveParameter] private readonly string $proxmoxSecretKey)
    {
    }

    public function isAvailable(): bool
    {
        $this->attempt();

        return null !== $this->secretBox;
    }

    /**
     * The reason encryption is unusable, for a log or an administrator-facing banner. Never
     * mentions the key itself, only what is wrong with it.
     */
    public function unavailableReason(): ?string
    {
        $this->attempt();

        return $this->failure;
    }

    /** @throws SecretBoxException when the key is missing or badly sized */
    public function get(): SecretBox
    {
        $this->attempt();

        if (null === $this->secretBox) {
            throw new SecretBoxException($this->failure ?? 'Proxmox encryption is unavailable.');
        }

        return $this->secretBox;
    }

    private function attempt(): void
    {
        if ($this->attempted) {
            return;
        }

        $this->attempted = true;

        try {
            $this->secretBox = new SecretBox($this->proxmoxSecretKey);
        } catch (SecretBoxException $exception) {
            $this->failure = $exception->getMessage();
        }
    }
}
