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
 *
 * **Two messages, deliberately.** `getMessage()` stays the developer's: English, precise, naming the
 * verb and the path, and it is what reaches the logs and Discord. It is a poor thing to show an
 * administrator, who gets `userMessageKey()` instead - a translation key with its parameters,
 * saying what happened and what to look at. Callers that have a translator use the second and fall
 * back to the first, which is why the key is optional: most refusals are already thrown as bare
 * keys and need nothing more.
 */
class ProxmoxUnavailableException extends \RuntimeException
{
    /**
     * @param array<string, string> $userMessageParameters
     */
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
        private readonly ?string $userMessageKey = null,
        private readonly array $userMessageParameters = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The host did not answer at all: a refused or blackholed port, a name that does not resolve, a
     * TLS layer that never came up. Named rather than built inline because `@Symfony`'s
     * `single_line_throw` puts every `throw` on one line, and four arguments' worth of it does not
     * fit on one worth reading.
     */
    public static function unreachable(string $address, string $developerMessage, ?\Throwable $previous = null): self
    {
        return new self($developerMessage, $previous, 'proxmoxHostUnreachableError', ['%address%' => $address]);
    }

    /** The port answered and then went quiet: something is listening, but it is not speaking TLS. */
    public static function tlsHandshakeFailed(string $address): self
    {
        return new self(\sprintf('The TLS handshake with %s did not complete.', $address), userMessageKey: 'proxmoxHostTlsHandshakeError', userMessageParameters: ['%address%' => $address]);
    }

    /**
     * The host answered and refused the identity. `$reason` is what Proxmox itself said about it -
     * `401 invalid token value!`, `401 no such user ('svc@pve')` - and it is the whole point of
     * this constructor: a bare "HTTP 401" names none of the four things an administrator could
     * have got wrong, and sends them looking through all of them.
     */
    public static function authenticationRefused(string $developerMessage, string $userMessageKey, string $reason): self
    {
        return new self($developerMessage, userMessageKey: $userMessageKey, userMessageParameters: ['%reason%' => $reason]);
    }

    /** The host is answering, just far too slowly to finish inside the request's budget. */
    public static function tooSlow(int $seconds): self
    {
        return new self(\sprintf('The host used more than %ds and the check was stopped.', $seconds), userMessageKey: 'proxmoxHostTooSlowError', userMessageParameters: ['%seconds%' => (string) $seconds]);
    }

    /** The translation key to show a human, when this failure has one. */
    public function userMessageKey(): ?string
    {
        return $this->userMessageKey;
    }

    /** @return array<string, string> */
    public function userMessageParameters(): array
    {
        return $this->userMessageParameters;
    }
}
