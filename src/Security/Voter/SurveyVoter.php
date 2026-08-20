<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SurveyCampaign;
use App\Entity\SurveySeries;
use App\Entity\SurveyTemplate;
use App\Entity\User;
use App\Repository\SurveyTargetRepository;
use App\Security\StructureAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what with a survey - see design/validated/surveys.md §6.
 *
 * The four attributes are deliberately asymmetric:
 *
 *  - EDIT / LAUNCH: the owner alone, with *no staff bypass*. Launching a survey under a
 *    colleague's name is not a gesture the application offers - the same asymmetry as
 *    StructureAccessChecker::isProgramReferentTeacher().
 *  - VIEW_RESULTS: the owner, plus staff. A satisfaction survey is an institution-wide object; a
 *    manager who cannot read it is a broken feature.
 *  - RESPOND: having a survey_target row without responded_at, on an open campaign. Nothing else,
 *    and above all no on-the-fly audience recomputation: the frozen target *is* the right to
 *    answer.
 *
 * And the rule this class is most likely to be broken by, so it is written here rather than only
 * in the design: **anonymity is not a permission, it is a property of the data.** The Voter has
 * nothing to say about it - on an anonymous campaign there simply is no name to show, to anybody.
 * There must never be an isAdmin() branch "lifting" anonymity; that is the change that would empty
 * the feature of its meaning.
 *
 * And, per the Proxmox lesson: a Voter never queries the AuthorizationChecker.
 */
class SurveyVoter extends Voter
{
    public const string EDIT = 'SURVEY_EDIT';
    public const string LAUNCH = 'SURVEY_LAUNCH';
    public const string VIEW_RESULTS = 'SURVEY_VIEW_RESULTS';
    public const string RESPOND = 'SURVEY_RESPOND';

    public function __construct(
        private readonly StructureAccessChecker $accessChecker,
        private readonly SurveyTargetRepository $targets,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::EDIT, self::LAUNCH => $subject instanceof SurveyTemplate || $subject instanceof SurveyCampaign,
            self::VIEW_RESULTS => $subject instanceof SurveyCampaign || $subject instanceof SurveySeries,
            self::RESPOND => $subject instanceof SurveyCampaign,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::EDIT, self::LAUNCH => $this->owns($subject, $user),
            self::VIEW_RESULTS => $this->owns($subject, $user) || $this->accessChecker->isStaff(),
            self::RESPOND => $subject instanceof SurveyCampaign && $this->mayRespond($subject, $user),
            default => false,
        };
    }

    /** The owner alone - staff included in nothing here, deliberately. */
    private function owns(mixed $subject, User $user): bool
    {
        return match (true) {
            $subject instanceof SurveyTemplate => $subject->getOwner() === $user,
            $subject instanceof SurveyCampaign => $subject->getCreatedBy() === $user,
            $subject instanceof SurveySeries => $subject->getOwner() === $user,
            default => false,
        };
    }

    private function mayRespond(SurveyCampaign $campaign, User $user): bool
    {
        if (!$campaign->isOpenNow()) {
            return false;
        }

        $target = $this->targets->findOneFor($campaign, $user);

        return null !== $target && !$target->hasResponded();
    }
}
