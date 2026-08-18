<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Thrown by App\Service\Crypto\SecretBox whenever a secret cannot be sealed or opened - a missing
 * or badly sized key at construction, an unknown envelope version, a corrupted payload, or a
 * failed authentication tag.
 *
 * Deliberately carries no context about the value it was handling: the message reaches Monolog and,
 * in production, Discord.
 */
class SecretBoxException extends \RuntimeException
{
}
