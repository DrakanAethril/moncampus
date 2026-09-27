<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * One read's answer, and the session to use for the next call - École Directe may hand a fresh
 * token back with any answer, and the old one stops working when it does.
 */
final readonly class EcoleDirecteReadResult
{
    public function __construct(
        public mixed $data,
        public EcoleDirecteSession $session,
    ) {
    }
}
