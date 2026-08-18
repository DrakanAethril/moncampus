<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * Thrown by App\Service\Proxmox\ProxmoxClient on anything that stops a call from producing an
 * answer: a transport failure, a TLS refusal, an HTTP status Proxmox uses for "no" (401/403), a
 * body that is not the `{"data": …}` envelope, or credentials that cannot be decrypted.
 *
 * Same contract as App\Service\GotenbergUnavailableException: every screen catches this one
 * specifically, so an unreachable hypervisor degrades to a badge and a message rather than a 500 -
 * which matters more here than there, because a Proxmox host being down is an ordinary Tuesday.
 */
class ProxmoxUnavailableException extends \RuntimeException
{
}
