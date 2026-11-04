<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\QuizTournament;
use App\Entity\User;
use App\Service\QuizTournament\QuizTournamentAccess;
use App\Service\QuizTournament\QuizTournamentClass;
use App\Service\QuizTournament\QuizTournamentEligibility;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * « Qui fait quoi » in the quiz tournaments (design/validated/quiz-tournament.md §2), the only
 * arbiter. The facts come from QuizTournamentAccess, the decisions from QuizTournamentEligibility -
 * this class only routes.
 *
 * - CREATE (no subject): an administrator, for any targeting.
 * - MANAGE (a QuizTournament): an administrator - edit, open.
 * - VIEW (a QuizTournament): standings and duel history - see QuizTournamentEligibility::canView().
 * - PLAY (a QuizTournament): challenge, accept, play - an eligible student while it runs.
 * - OPEN_ROUND (a QuizTournament, or a QuizTournamentClass for one class): a teacher of a targeted
 *   formation - for that formation - while it runs.
 *
 * Callers turn a refusal into a **404**, never a 403, like FeatureAccessSubscriber.
 */
class QuizTournamentVoter extends Voter
{
    public const string CREATE = 'QUIZ_TOURNAMENT_CREATE';
    public const string MANAGE = 'QUIZ_TOURNAMENT_MANAGE';
    public const string VIEW = 'QUIZ_TOURNAMENT_VIEW';
    public const string PLAY = 'QUIZ_TOURNAMENT_PLAY';
    public const string OPEN_ROUND = 'QUIZ_TOURNAMENT_OPEN_ROUND';

    public function __construct(
        private readonly QuizTournamentAccess $access,
        private readonly ClockInterface $clock,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::CREATE => true,
            self::MANAGE, self::VIEW, self::PLAY => $subject instanceof QuizTournament,
            self::OPEN_ROUND => $subject instanceof QuizTournament || $subject instanceof QuizTournamentClass,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        $now = $this->clock->now();

        return match ($attribute) {
            self::CREATE => QuizTournamentEligibility::canManage($roles),
            self::MANAGE => QuizTournamentEligibility::canManage($roles),
            self::VIEW => $subject instanceof QuizTournament && $this->canView($user, $subject),
            self::PLAY => $subject instanceof QuizTournament && $this->access->canPlay($subject, $user, $now),
            self::OPEN_ROUND => $this->canOpenRound($user, $subject, $now),
            default => false,
        };
    }

    private function canView(User $user, QuizTournament $tournament): bool
    {
        $roles = $user->getRoles();
        if (QuizTournamentEligibility::canManage($roles)) {
            return true;
        }
        if (!$tournament->isOpened()) {
            return false;
        }

        // Each fact asked only when it can still change the answer: both walk the rosters.
        $student = \in_array('ROLE_STUDENT', $roles, true);
        $teacher = \in_array('ROLE_TEACHER', $roles, true);

        return QuizTournamentEligibility::canView(
            $roles,
            $this->access->isFeatureLit($user),
            true,
            $tournament->getCreatedBy() === $user,
            $student && $this->access->isReached($tournament, $user),
            $teacher && $this->access->teachesATargetedProgram($user, $tournament),
        );
    }

    private function canOpenRound(User $user, mixed $subject, \DateTimeImmutable $now): bool
    {
        [$tournament, $program] = match (true) {
            $subject instanceof QuizTournamentClass => [$subject->tournament, $subject->program],
            $subject instanceof QuizTournament => [$subject, null],
            default => [null, null],
        };
        if (null === $tournament || !\in_array('ROLE_TEACHER', $user->getRoles(), true)) {
            return false;
        }

        return QuizTournamentEligibility::canOpenRound(
            $user->getRoles(),
            $this->access->isFeatureLit($user),
            $tournament->isInProgress($now),
            null === $program
                ? $this->access->teachesATargetedProgram($user, $tournament)
                : $this->access->teachesTargetedProgram($user, $tournament, $program),
        );
    }
}
