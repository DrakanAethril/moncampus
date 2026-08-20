<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a failure into the sentence an administrator reads.
 *
 * One place, because the same answer is needed in three: the form's two buttons, which hand it back
 * as JSON, and App\Service\Proxmox\ProxmoxHostChecker, which stores it on the host and whose
 * `lastCheckMessage` is printed **raw** by the hosts list and the hub. That last one is why the
 * resolution happens here and not in a Twig filter: a translation key stored in the column would be
 * shown as a translation key.
 *
 * The rule is short. A failure that names a key gets the key, translated with its parameters;
 * anything else goes through the translator anyway, because most refusals of this area are thrown
 * as bare keys already (`proxmoxRefusalBusy`, `proxmoxRefusalOutOfScope`) and an unknown key is
 * answered by `trans()` with itself - so a plain English sentence survives unchanged rather than
 * becoming empty.
 *
 * What does *not* come here is the developer's half of the message. It stays on the exception, and
 * it is what reaches the logs.
 */
class ProxmoxFailureMessage
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function readable(ProxmoxUnavailableException $exception): string
    {
        $key = $exception->userMessageKey();

        return null !== $key
            ? $this->translator->trans($key, $exception->userMessageParameters())
            : $this->translator->trans($exception->getMessage());
    }
}
