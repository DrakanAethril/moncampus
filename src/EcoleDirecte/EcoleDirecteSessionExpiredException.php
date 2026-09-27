<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * The connection is over - École Directe dropped its token, or the sealed session the browser sent
 * back is past its time or was not sealed for this person. Either way the only way on is to sign in
 * again, which is why the screen tells this one apart from every other refusal.
 */
final class EcoleDirecteSessionExpiredException extends EcoleDirecteException
{
    public function __construct(?int $apiCode = null, ?\Throwable $previous = null)
    {
        parent::__construct('ecoleDirecteSessionExpiredMessage', $apiCode, '', $previous);
    }
}
