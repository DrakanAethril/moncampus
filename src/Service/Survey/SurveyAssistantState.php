<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Enum\SurveyAssistantPath;

/**
 * What the survey import assistant remembers between its steps, and nothing else.
 *
 * It lives in the session rather than in the query string because step 2 ends *outside* the
 * application: the author copies a prompt, converses with a model, and comes back with a document,
 * sometimes a quarter of an hour later. Coming back must resume rather than restart - the same
 * reason App\Service\QuizAssistantState keeps its own state there.
 *
 * Shorter than its quiz counterpart by exactly the two things a survey has no use for: no séquence
 * or séance scope (see App\Enum\SurveyAssistantPath), and no live-contest filter.
 */
final class SurveyAssistantState
{
    public function __construct(
        public readonly ?SurveyAssistantPath $path = null,
        public readonly SurveyAssistantRequest $request = new SurveyAssistantRequest(),
    ) {
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            path: \is_string($raw['path'] ?? null) ? SurveyAssistantPath::tryFrom($raw['path']) : null,
            request: SurveyAssistantRequest::fromArray(\is_array($raw['request'] ?? null) ? $raw['request'] : []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path?->value,
            'request' => $this->request->toArray(),
        ];
    }

    public function withRequest(SurveyAssistantRequest $request): self
    {
        return new self($this->path, $request);
    }
}
