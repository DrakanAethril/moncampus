<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\QuizAssistantPath;

/**
 * What the quiz assistant remembers between its steps, and nothing else.
 *
 * It lives in the session rather than in the query string because step 2 ends *outside* the
 * application: the teacher copies a prompt, converses with a model, and comes back with a document,
 * sometimes a quarter of an hour later. Coming back must resume rather than restart - the same
 * reason App\Controller\SequenceImportController keeps its own state there.
 *
 * The ticked question types are deliberately **not** here. They are read straight off the checkboxes
 * by assets/controllers/quiz_prompt_builder_controller.js, which owns them today; persisting them
 * would mean a round trip per tick, and the prompt is rebuilt on that page anyway. `$liveOnly` is
 * the exception, because it arrives from a link (the « concours live » entry of a séance's menu)
 * rather than from a click on this page.
 *
 * A value object on primitives: no Request, no session, no repository, so what it accepts is
 * readable in one place and testable without a kernel.
 */
final class QuizAssistantState
{
    public function __construct(
        public readonly ?QuizAssistantPath $path = null,
        public readonly ?int $sequenceId = null,
        public readonly ?int $seanceId = null,
        public readonly bool $liveOnly = false,
        public readonly QuizAssistantRequest $request = new QuizAssistantRequest(),
    ) {
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $seanceId = self::id($raw['seanceId'] ?? null);
        // A séance wins: a link naming both names the parent for context, and the narrower scope is
        // the one the teacher clicked. Keeping both would let two branches disagree downstream.
        $sequenceId = null !== $seanceId ? null : self::id($raw['sequenceId'] ?? null);

        $path = \is_string($raw['path'] ?? null) ? QuizAssistantPath::tryFrom($raw['path']) : null;
        // The course path without a course would build a prompt that announces a lesson and carries
        // none. Reading it back as "no path" is what sends the teacher to step 1, where the missing
        // answer is - rather than to a step 2 that cannot be right.
        if (QuizAssistantPath::Course === $path && null === $seanceId && null === $sequenceId) {
            $path = null;
        }

        return new self(
            path: $path,
            sequenceId: $sequenceId,
            seanceId: $seanceId,
            liveOnly: (bool) ($raw['liveOnly'] ?? false),
            request: QuizAssistantRequest::fromArray(\is_array($raw['request'] ?? null) ? $raw['request'] : []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path?->value,
            'sequenceId' => $this->sequenceId,
            'seanceId' => $this->seanceId,
            'liveOnly' => $this->liveOnly,
            'request' => $this->request->toArray(),
        ];
    }

    public function isFromCourse(): bool
    {
        return QuizAssistantPath::Course === $this->path;
    }

    /**
     * The scope as route parameters - what a link between two steps has to carry, and the shape
     * App\Controller\QuizImportController already stores so the preview can offer « rattacher à la
     * séance … ».
     *
     * @return array<string, int>
     */
    public function scopeParams(): array
    {
        if (null !== $this->seanceId) {
            return ['seance' => $this->seanceId];
        }

        if (null !== $this->sequenceId) {
            return ['sequence' => $this->sequenceId];
        }

        return [];
    }

    public function withRequest(QuizAssistantRequest $request): self
    {
        return new self($this->path, $this->sequenceId, $this->seanceId, $this->liveOnly, $request);
    }

    private static function id(mixed $value): ?int
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
