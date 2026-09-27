<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * École Directe refused, or answered something this client cannot read.
 *
 * The message is a translation key, never École Directe's own sentence: the screen speaks with one
 * voice. École Directe's `message` travels alongside for the codes this client does not know, where
 * it is the only clue the teacher can pass on.
 */
class EcoleDirecteException extends \RuntimeException
{
    public function __construct(
        string $messageKey,
        public readonly ?int $apiCode = null,
        public readonly string $apiMessage = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
