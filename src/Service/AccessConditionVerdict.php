<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whether one object is open to one student, and - when it is not - what is missing.
 *
 * Not a boolean on purpose: what the student reads on a locked row is the way out of it
 * ("Disponible une fois le TP 3 déposé"), so the unmet leaves travel with the answer. The sentences
 * themselves are added afterwards by AccessConditionLabeler, which is the only object that knows
 * what this reader is allowed to be told about.
 */
final readonly class AccessConditionVerdict
{
    /**
     * @param list<AccessConditionLeaf> $unmet
     * @param list<string>              $reasons
     */
    public function __construct(
        public bool $satisfied,
        public array $unmet = [],
        public array $reasons = [],
    ) {
    }

    public static function open(): self
    {
        return new self(true);
    }

    /** @param list<string> $reasons */
    public function withReasons(array $reasons): self
    {
        return new self($this->satisfied, $this->unmet, $reasons);
    }
}
