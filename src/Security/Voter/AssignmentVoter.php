<?php

namespace App\Security\Voter;

use App\Entity\Assignment;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Service\AssignmentAudienceResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to an Assignment: staff can manage any Assignment on a Program they can see
 * (create/edit/delete, view the full roster/status). A student can only submit to (view + upload
 * files for) an Assignment whose audience they're actually in - see AssignmentAudienceResolver.
 */
class AssignmentVoter extends Voter
{
    public const string MANAGE = 'ASSIGNMENT_MANAGE';
    public const string SUBMIT = 'ASSIGNMENT_SUBMIT';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
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
            return $this->accessChecker->isStaff();
        }

        return $this->audienceResolver->isInAudience($assignment, $user);
    }
}
