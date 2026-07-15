<?php

namespace App\Security\Voter;

use App\Entity\SignupList;
use App\Entity\User;
use App\Repository\SignupListRegistrationRepository;
use App\Security\StructureAccessChecker;
use App\Service\AudienceResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scopes access to a SignupList - same MANAGE/action-attribute shape as AssignmentVoter, one layer
 * up in the assignment-submission-box feature. "Can I even see this list" is deliberately NOT one
 * of these attributes: that's plain audience membership, already covered by the generic
 * AudienceTargetableVoter::VIEW (SignupList implements AudienceTargetable) plus MANAGE below for
 * the creator/staff override - SignupListController combines the two rather than duplicating
 * audience-resolution logic here.
 */
class SignupListVoter extends Voter
{
    public const string MANAGE = 'SIGNUP_LIST_MANAGE';
    public const string REGISTER = 'SIGNUP_LIST_REGISTER';
    public const string UNREGISTER = 'SIGNUP_LIST_UNREGISTER';
    public const string VIEW_ROSTER = 'SIGNUP_LIST_VIEW_ROSTER';

    public function __construct(
        private readonly StructureAccessChecker $structureAccessChecker,
        private readonly AudienceResolver $audienceResolver,
        private readonly SignupListRegistrationRepository $registrationRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::MANAGE, self::REGISTER, self::UNREGISTER, self::VIEW_ROSTER], true) && $subject instanceof SignupList;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var SignupList $signupList */
        $signupList = $subject;
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Creator-or-staff manages the list itself (edit/delete/attachments) and always sees the
        // roster, regardless of $publicRoster/audience - same "owner or staff" shape as
        // AssignmentVoter::MANAGE.
        $isManager = $signupList->getCreatedBy() === $user || $this->structureAccessChecker->isStaff();

        $existingRegistration = $this->registrationRepository->findOneForSignupListAndUser($signupList, $user);

        return match ($attribute) {
            self::MANAGE => $isManager,
            // Registering/unregistering is never staff-privileged by itself - being staff doesn't
            // put you in a list's audience, it only lets you manage the list. REGISTER also
            // requires not already being registered - audience membership alone isn't enough,
            // or the "S'inscrire"/"Se désinscrire" toggle in the template would never flip once
            // registered (both would stay grantable at once).
            self::REGISTER => null === $existingRegistration && $this->audienceResolver->isVisibleTo($signupList, $user),
            self::UNREGISTER => null !== $existingRegistration,
            self::VIEW_ROSTER => $isManager || ($signupList->isPublicRoster() && $this->audienceResolver->isVisibleTo($signupList, $user)),
        };
    }
}
