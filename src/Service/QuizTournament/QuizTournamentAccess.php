<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\QuizTournamentChallengeRepository;
use App\Repository\QuizTournamentDuelRepository;
use App\Security\FeatureAccess;
use App\Service\StructureTargeting\StructureProgramFacts;
use App\Service\StructureTargeting\StructureTargeting;
use App\Service\StructureTargeting\StructureTargetRule;
use App\Service\StructureTargeting\StructureTargets;

/**
 * The facts the tournament's rules decide on, read from the database
 * (design/validated/quiz-tournament.md §2 and §4.1). Read by App\Security\Voter\QuizTournamentVoter
 * and by the screens; the decisions stay in QuizTournamentEligibility and
 * QuizTournamentChallengeRules.
 *
 * Nothing is memoised: eligibility is read at every challenge, acceptance and start (§2), and the
 * FrankenPHP worker keeps this service from one request to the next.
 */
class QuizTournamentAccess
{
    public function __construct(
        private readonly FeatureAccess $featureAccess,
        private readonly StructureTargeting $targeting,
        private readonly ProgramRepository $programs,
        private readonly QuizTournamentChallengeRepository $challenges,
        private readonly QuizTournamentDuelRepository $duels,
    ) {
    }

    public function isFeatureLit(User $user): bool
    {
        return $this->featureAccess->isEnabled(Feature::QuizTournaments, $user);
    }

    /** Does the tournament's targeting reach this person (active formation, test zone)? */
    public function isReached(QuizTournament $tournament, User $user): bool
    {
        return $this->targeting->reaches($tournament, $user);
    }

    public function isEligible(QuizTournament $tournament, User $user): bool
    {
        return \in_array('ROLE_STUDENT', $user->getRoles(), true)
            && QuizTournamentEligibility::isEligible($user->getRoles(), $this->isReached($tournament, $user));
    }

    public function canPlay(QuizTournament $tournament, User $user, \DateTimeImmutable $now): bool
    {
        return QuizTournamentEligibility::canPlay(
            $user->getRoles(),
            $this->isFeatureLit($user),
            $this->isEligible($tournament, $user),
            $tournament->isInProgress($now),
        );
    }

    /**
     * The targeted formations this person teaches - active, reached by the targeting, on their side
     * of the test zone - in the targeting's order (most recent first): the classes a round may be
     * opened for.
     *
     * @return list<int> program ids
     */
    public function taughtTargetedProgramIds(User $teacher, QuizTournament $tournament): array
    {
        $targets = StructureTargets::of($tournament);
        if ($targets->isEmpty()) {
            return [];
        }

        $taught = $this->programs->findIdsWithUserAsTeacher($teacher);
        if ([] === $taught) {
            return [];
        }

        $reached = StructureTargetRule::reachedPrograms($targets, $this->programs->findStructureFacts(), $teacher->isTestUser());

        return array_values(array_map(
            static fn (StructureProgramFacts $program): int => $program->programId,
            array_filter($reached, static fn (StructureProgramFacts $program): bool => \in_array($program->programId, $taught, true)),
        ));
    }

    public function teachesATargetedProgram(User $teacher, QuizTournament $tournament): bool
    {
        return [] !== $this->taughtTargetedProgramIds($teacher, $tournament);
    }

    public function teachesTargetedProgram(User $teacher, QuizTournament $tournament, Program $program): bool
    {
        return \in_array($program->getId(), $this->taughtTargetedProgramIds($teacher, $tournament), true);
    }

    /** Everything QuizTournamentChallengeRules needs to say whether A may challenge B now. */
    public function challengeFacts(QuizTournament $tournament, User $challenger, User $challenged, \DateTimeImmutable $now): QuizTournamentChallengeFacts
    {
        $same = $challenger === $challenged || (null !== $challenger->getId() && $challenger->getId() === $challenged->getId());
        [$from, $to] = QuizTournamentChallengeRules::dayBounds($now);

        return new QuizTournamentChallengeFacts(
            $tournament->isInProgress($now),
            $this->isFeatureLit($challenger) && $this->isEligible($tournament, $challenger),
            $this->isEligible($tournament, $challenged),
            $same,
            $this->challenges->countPendingOutgoing($tournament, $challenger, $now),
            $this->challenges->countPendingIncoming($tournament, $challenged, $now),
            $this->challenges->lastRefusalAt($tournament, $challenger, $challenged),
            [
                ...$this->challenges->encounterTimes($tournament, $challenger, $challenged, $from, $to),
                ...$this->duels->roundEncounterTimes($tournament, $challenger, $challenged, $from, $to),
            ],
        );
    }

    /**
     * The pairs among these people that already met three times today (challenges either way and
     * round duels) - what QuizTournamentRoundPairing avoids.
     *
     * @param list<int> $userIds
     *
     * @return list<array{0: int, 1: int}>
     */
    public function cappedPairs(QuizTournament $tournament, array $userIds, \DateTimeImmutable $now): array
    {
        [$from, $to] = QuizTournamentChallengeRules::dayBounds($now);
        $counts = $this->challenges->pairEncounterCounts($tournament, $userIds, $from, $to);
        foreach ($this->duels->roundPairEncounterCounts($tournament, $userIds, $from, $to) as $key => $count) {
            $counts[$key] = ($counts[$key] ?? 0) + $count;
        }

        $capped = [];
        foreach ($counts as $key => $count) {
            if ($count >= QuizTournamentChallengeRules::DAILY_PAIR_CAP) {
                [$a, $b] = array_map(intval(...), explode('-', $key));
                $capped[] = [$a, $b];
            }
        }

        return $capped;
    }
}
