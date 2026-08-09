<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A CSV the importer cannot use at all (unreadable, no header, not a single usable row) - as
 * opposed to a bad *line*, which App\Service\QuizCsvImporter reports in the payload's `errors`
 * list and simply skips. Carries a translation key rather than a message, so the controller
 * decides the locale (same reasoning as App\Service\LiveTemplateNotEligibleException's issue keys).
 */
final class QuizCsvImportException extends \RuntimeException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(private readonly string $messageKey, private readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /** @return array<string, string|int> */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
