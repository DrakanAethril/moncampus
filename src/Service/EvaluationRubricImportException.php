<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A « moncampus-bareme/1 » document unusable as a whole - carries a translation key, like
 * App\Service\SequenceImportException, so the caller says it in the reader's language.
 */
final class EvaluationRubricImportException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
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
