<?php

declare(strict_types=1);

namespace App\Service\Survey;

/**
 * A document the reader cannot use at all (not JSON, wrong format tag, not a single question) - as
 * opposed to a bad *question*, which App\Service\Survey\SurveyJsonImporter reports in the payload's
 * `errors` list and simply skips. One unusable question must never cost the author the rest of the
 * questionnaire.
 *
 * Carries a translation key rather than a message, so the controller decides the locale - the same
 * shape as App\Service\QuizCsvImportException, and a class of its own rather than a reuse: the two
 * imports share no reader, and a survey error naming "quiz" in its class is the kind of detail that
 * ends up on screen.
 */
final class SurveyImportException extends \RuntimeException
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
