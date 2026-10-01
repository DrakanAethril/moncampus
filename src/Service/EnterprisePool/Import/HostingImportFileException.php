<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool\Import;

/** The file cannot be read as an import at all - a translation key and its parameters. */
final class HostingImportFileException extends \RuntimeException
{
    /** @param array<string, string> $parameters */
    public function __construct(
        private readonly string $messageKey,
        private readonly array $parameters = [],
    ) {
        parent::__construct($messageKey);
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /** @return array<string, string> */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
