<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * What QuizTournamentChallengeRules decides a challenge on, loaded beforehand
 * (QuizTournamentAccess::challengeFacts()).
 *
 * « En attente » means neither accepted, nor refused, nor expired: a pending row whose 24 hours are
 * over counts as expired whether or not a task has rewritten its status yet.
 */
final readonly class QuizTournamentChallengeFacts
{
    /**
     * @param ?\DateTimeImmutable       $lastRefusalByChallengedAt when the challenged last refused a challenge of this challenger
     * @param list<\DateTimeImmutable>  $pairEncounters            when the two met: every challenge between them, either way and
     *                                                             whatever became of it, and every round duel between them
     */
    public function __construct(
        public bool $inProgress,
        public bool $challengerEligible,
        public bool $challengedEligible,
        public bool $samePlayer,
        public int $challengerPendingOutgoing,
        public int $challengedPendingIncoming,
        public ?\DateTimeImmutable $lastRefusalByChallengedAt,
        public array $pairEncounters,
    ) {
    }
}
