<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * Where a login attempt stands: either signed in, or stopped on École Directe's identity question.
 *
 * A refusal is not an outcome but an App\EcoleDirecte\EcoleDirecteException - there is nothing to
 * continue from it.
 */
final readonly class EcoleDirecteLoginOutcome
{
    /**
     * @param list<array{label: string, value: string}> $choices the question's propositions, the
     *                                                           label decoded for the screen and the
     *                                                           value kept exactly as École Directe
     *                                                           sent it, since that is what it wants back
     */
    private function __construct(
        public ?EcoleDirecteSession $session,
        public ?EcoleDirecteHandshake $pending,
        public string $question = '',
        public array $choices = [],
    ) {
    }

    public static function connected(EcoleDirecteSession $session): self
    {
        return new self($session, null);
    }

    /** @param list<array{label: string, value: string}> $choices */
    public static function challenged(EcoleDirecteHandshake $pending, string $question, array $choices): self
    {
        return new self(null, $pending, $question, $choices);
    }

    public function isConnected(): bool
    {
        return null !== $this->session;
    }
}
