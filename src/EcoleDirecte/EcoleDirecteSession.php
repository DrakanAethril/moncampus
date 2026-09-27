<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * An open École Directe connection: the handshake every call replays, and the account it opened.
 */
final readonly class EcoleDirecteSession
{
    public function __construct(
        public EcoleDirecteHandshake $handshake,
        public EcoleDirecteAccount $account,
    ) {
    }

    public function withHandshake(EcoleDirecteHandshake $handshake): self
    {
        return new self($handshake, $this->account);
    }
}
