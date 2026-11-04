<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentRound;

/**
 * What a student's tournament screens say of a round in class (design/validated/quiz-tournament.md
 * §8), as QuizTournamentRounds::entryFor() reads it: a round that expects them is open
 * (« Rejoindre la ronde »), they joined it (« attends que ton enseignant lance »), the pairing gave
 * them a duel still running, made them sit out, or started without them (gone from the page, in a
 * duel elsewhere, no longer eligible).
 */
final readonly class QuizTournamentRoundEntry
{
    public const string OPEN = 'open';
    public const string JOINED = 'joined';
    public const string PLAYING = 'playing';
    public const string EXEMPT = 'exempt';
    public const string LEFT_OUT = 'left_out';

    public function __construct(
        public QuizTournamentRound $round,
        public string $state,
        public ?QuizTournamentDuel $duel = null,
    ) {
    }
}
