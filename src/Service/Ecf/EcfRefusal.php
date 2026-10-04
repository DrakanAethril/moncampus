<?php

declare(strict_types=1);

namespace App\Service\Ecf;

/**
 * A write or a visa the ECF rules refuse, carrying the translation key and parameters the screen
 * shows as they are.
 */
final class EcfRefusal extends \DomainException
{
    /** @param array<string, string> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}
