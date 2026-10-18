<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\User;
use App\Service\SeriousGame\SeriousGameAccess;
use App\Service\SeriousGame\SeriousGameRules;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * « Qui joue, qui lit » in the serious games (design/validated/serious-games.md §3.2). The facts
 * come from SeriousGameAccess, the decision from SeriousGameRules - this class only routes.
 *
 * - PLAY (a SeriousGame): playable for the person.
 * - COLLECT (no subject): a student, who collects pieces; an adult plays without collecting.
 * - READ_STUDENT (a User): the student themself, the administrator, a teacher of one of the
 *   student's active classes - for the whole history.
 * - READ_CLASS (a Program): the administrator, or a teacher of that class.
 * - READ_AGGREGATE (no subject): staff, staff-lead, administrator - figures without names.
 * - MANAGE (no subject): the administrator - publication, answer kinds, templates.
 *
 * Callers turn a refusal into a **404**, never a 403, like FeatureAccessSubscriber.
 */
class SeriousGameVoter extends Voter
{
    public const string PLAY = 'SERIOUS_GAME_PLAY';
    public const string COLLECT = 'SERIOUS_GAME_COLLECT';
    public const string READ_STUDENT = 'SERIOUS_GAME_READ_STUDENT';
    public const string READ_CLASS = 'SERIOUS_GAME_READ_CLASS';
    public const string READ_AGGREGATE = 'SERIOUS_GAME_READ_AGGREGATE';
    public const string MANAGE = 'SERIOUS_GAME_MANAGE';

    public function __construct(private readonly SeriousGameAccess $access)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::PLAY => $subject instanceof SeriousGame,
            self::READ_STUDENT => $subject instanceof User,
            self::READ_CLASS => $subject instanceof Program,
            self::COLLECT, self::READ_AGGREGATE, self::MANAGE => true,
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

        return match ($attribute) {
            self::PLAY => $subject instanceof SeriousGame && $this->access->canPlay($user, $subject),
            self::COLLECT => SeriousGameRules::canCollect($roles),
            self::READ_STUDENT => $subject instanceof User && SeriousGameRules::canReadStudent(
                $roles,
                (int) $user->getId(),
                (int) $subject->getId(),
                // Asked only when it can change the answer: the query walks the student's classes.
                \in_array('ROLE_TEACHER', $roles, true) && $user->getId() !== $subject->getId() && $this->access->teachesAnActiveClassOf($user, $subject),
            ),
            self::READ_CLASS => $subject instanceof Program && SeriousGameRules::canReadClass($roles, $this->access->teaches($user, $subject)),
            self::READ_AGGREGATE => SeriousGameRules::canReadAggregate($roles),
            self::MANAGE => SeriousGameRules::canManage($roles),
            default => false,
        };
    }
}
