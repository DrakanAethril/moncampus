<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\ProxmoxHost;
use App\Service\Crypto\SecretBoxException;
use App\Service\Crypto\SecretBoxProvider;

/**
 * Turns what an administrator typed into the two sealed columns of a host, and decides what a blank
 * field means.
 *
 * Blank is not "erase": it is "keep what is there". That single rule is what makes the form usable
 * at all once a host exists - the field is never prefilled (nothing can prefill it), so every edit
 * of a label or a VMID range would otherwise wipe the credentials.
 *
 * The one exception is the provisioning set, where clearing the *username* does mean "this host no
 * longer creates anything": leaving a sealed provisioning secret behind an emptied account would
 * keep a VM.Allocate credential in the database that nothing points at any more.
 *
 * Together with App\Service\Proxmox\ProxmoxClientFactory (the only reader), this is the only writer:
 * plaintext enters the application here and leaves it there, and nowhere else.
 */
class ProxmoxSecretWriter
{
    public function __construct(private readonly SecretBoxProvider $secretBoxProvider)
    {
    }

    /**
     * @param string $secret          the submitted operating secret - blank keeps the stored one
     * @param string $provisionSecret the submitted provisioning secret - blank keeps the stored one
     *
     * @throws SecretBoxException when encryption is unavailable and there is something to seal
     */
    public function apply(
        ProxmoxHost $host,
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $provisionSecret,
    ): void {
        if ('' !== $secret) {
            $host->setSecretCipher($this->secretBoxProvider->get()->seal($secret));
        }

        $provisionUsername = $host->getProvisionUsername();

        if (null === $provisionUsername || '' === trim($provisionUsername)) {
            $host->setProvisionUsername(null);
            $host->setProvisionRealm(null);
            $host->setProvisionTokenName(null);
            $host->setProvisionSecretCipher(null);

            return;
        }

        if ('' !== $provisionSecret) {
            $host->setProvisionSecretCipher($this->secretBoxProvider->get()->seal($provisionSecret));
        }
    }
}
