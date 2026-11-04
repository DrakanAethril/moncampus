<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * What QuizTournamentRoundRules::openRefusal() decides on, loaded beforehand: the class, whether the
 * teacher may open a round for it now (QuizTournamentVoter::OPEN_ROUND), whether a round is already
 * open for it, and the restriction asked for - an Option (with the class's own options), or a group
 * of a saved lot (the lot's class, whether the teacher owns it or was shared it, the group's index
 * and how many groups the lot holds).
 */
final readonly class QuizTournamentRoundOpening
{
    /** @param list<int> $classOptionIds */
    public function __construct(
        public int $programId,
        public bool $canOpen,
        public bool $classHasOpenRound,
        public ?int $optionId,
        public array $classOptionIds,
        public ?int $batchId,
        public ?int $batchProgramId,
        public bool $batchVisible,
        public ?int $groupIndex,
        public int $groupCount,
    ) {
    }
}
