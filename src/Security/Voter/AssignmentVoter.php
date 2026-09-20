<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Assignment;
use App\Entity\User;
use App\Service\AssignmentAudienceResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to an Assignment: MANAGE belongs to the teacher who gave it and to nobody else -
 * not a colleague of the same class, not staff, not an administrator. A travail is its author's
 * (reading its roster and its submissions is reading what that teacher asked for), so there is
 * deliberately no staff bypass here: an administrator manages their own travaux like anyone else.
 * A student can only submit to (view + upload files for) an Assignment whose audience they're
 * actually in - see AssignmentAudienceResolver.
 */
class AssignmentVoter extends Voter
{
    public const string MANAGE = 'ASSIGNMENT_MANAGE';
    public const string SUBMIT = 'ASSIGNMENT_SUBMIT';

    public function __construct(
        private readonly AssignmentAudienceResolver $audienceResolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::SUBMIT], true) && $subject instanceof Assignment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Assignment $assignment */
        $assignment = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if (self::MANAGE === $attribute) {
            return $assignment->getCreatedBy() === $user;
        }

        return $this->audienceResolver->isInAudience($assignment, $user);
    }
}
