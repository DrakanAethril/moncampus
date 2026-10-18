<?php

declare(strict_types=1);

namespace App\SeriousGame\Engine;

/**
 * The correction of one answer (design/validated/serious-games.md §5.5).
 *
 * - `ok`: right, in the shape asked;
 * - `misconception`: the typical error a wrong answer was recognised as, if any;
 * - `formIssue`: the value is right but not in the shape asked (« développer et réduire » given in
 *   brackets) - **signalled without counting wrong**: the student is asked to write it again;
 * - `unreadable`: the text does not read as an answer of this type - also asked again;
 * - `dontKnow`: « Je ne sais pas » - not right, attributed to no error.
 */
final readonly class CheckResult
{
    public function __construct(
        public bool $ok,
        public ?string $misconception = null,
        public ?string $formIssue = null,
        public bool $unreadable = false,
        public bool $dontKnow = false,
    ) {
    }

    /** Whether the answer is settled - right or wrong - rather than to be written again. */
    public function isFinal(): bool
    {
        return null === $this->formIssue && !$this->unreadable;
    }
}
