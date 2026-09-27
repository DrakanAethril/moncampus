<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * One call's answer - a read or a send - and the session to use for the next call: École Directe
 * may hand a fresh token back with any answer, and the old one stops working when it does.
 */
final readonly class EcoleDirecteResult
{
    public function __construct(
        public mixed $data,
        public EcoleDirecteSession $session,
    ) {
    }
}
