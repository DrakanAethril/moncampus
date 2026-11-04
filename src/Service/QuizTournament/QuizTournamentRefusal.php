<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * A tournament gesture the rules refuse - a challenge, an answer, a « Prêt » - with the sentence the
 * screen shows: a translation key and its parameters. Thrown by QuizTournamentChallenges and
 * QuizTournamentRooms after they read the facts again, server-side, whatever the page offered.
 */
final class QuizTournamentRefusal extends \RuntimeException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($messageKey);
    }
}
