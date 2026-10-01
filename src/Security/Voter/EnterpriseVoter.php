<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enterprise;
use App\Entity\User;
use App\Enum\Feature;
use App\Security\FeatureAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * **Who sees what of the vivier d'entreprises** - the matrix of
 * design/validated/vivier-entreprises.md §3, written once. Templates, controllers, the import and
 * the mobile API ask here; none of them tests a role.
 *
 *   - everybody who reaches the fiche: the hostings, **stage and alternance told apart**, their
 *     filière and years, and the contacts declared « communicables aux étudiants »;
 *   - teachers and the administration staff, once `enterprise_pool` is lit for them: every
 *     contact, the teachers who know the company, the team's notes, the vivier list - and they
 *     declare themselves a contact and write notes;
 *   - **ROLE_ADMIN alone**: the names of the students hosted, the two filters « a accueilli un
 *     stagiaire / un alternant », and everything that writes the vivier (D7, Q1).
 *
 * Every attribute but VIEW answers on the subject null too: the search screen asks « may this
 * person filter on hostings » before it has any company in hand. The test-world asymmetry is
 * VIEW's: a test account reaches test employers only.
 */
class EnterpriseVoter extends Voter
{
    public const string VIEW = 'ENTERPRISE_VIEW';
    public const string VIEW_SHARED_CONTACTS = 'ENTERPRISE_VIEW_SHARED_CONTACTS';
    public const string VIEW_CONTACTS = 'ENTERPRISE_VIEW_CONTACTS';
    public const string VIEW_TEACHER_CONTACTS = 'ENTERPRISE_VIEW_TEACHER_CONTACTS';
    public const string VIEW_STAFF_NOTES = 'ENTERPRISE_VIEW_STAFF_NOTES';
    public const string WRITE_STAFF_NOTE = 'ENTERPRISE_WRITE_STAFF_NOTE';
    public const string DECLARE_TEACHER_CONTACT = 'ENTERPRISE_DECLARE_TEACHER_CONTACT';
    public const string BROWSE_POOL = 'ENTERPRISE_BROWSE_POOL';
    public const string VIEW_HOSTED_STUDENTS = 'ENTERPRISE_VIEW_HOSTED_STUDENTS';
    public const string FILTER_HOSTINGS = 'ENTERPRISE_FILTER_HOSTINGS';
    public const string MANAGE = 'ENTERPRISE_MANAGE';

    private const array TEAM = [
        self::VIEW_CONTACTS, self::VIEW_TEACHER_CONTACTS, self::VIEW_STAFF_NOTES,
        self::WRITE_STAFF_NOTE, self::DECLARE_TEACHER_CONTACT, self::BROWSE_POOL,
    ];

    private const array ADMINISTRATOR = [self::VIEW_HOSTED_STUDENTS, self::FILTER_HOSTINGS, self::MANAGE];

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly FeatureAccess $features,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        $known = self::VIEW === $attribute || self::VIEW_SHARED_CONTACTS === $attribute
            || \in_array($attribute, self::TEAM, true) || \in_array($attribute, self::ADMINISTRATOR, true);

        return $known && (null === $subject || $subject instanceof Enterprise);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($subject instanceof Enterprise && $user->isTestUser() && !$subject->isTestEnterprise()) {
            return false;
        }

        if (self::VIEW === $attribute || self::VIEW_SHARED_CONTACTS === $attribute) {
            return true;
        }

        if (\in_array($attribute, self::ADMINISTRATOR, true)) {
            return $this->decisions->decide($token, ['ROLE_ADMIN']);
        }

        // The team: the administrator always; a teacher or the administration staff once the
        // vivier is lit for them.
        if ($this->decisions->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        $team = $this->decisions->decide($token, ['ROLE_TEACHER'])
            || $this->decisions->decide($token, ['ROLE_STAFF'])
            || $this->decisions->decide($token, ['ROLE_STAFF-LEAD']);

        return $team && $this->features->isEnabled(Feature::EnterprisePool, $user);
    }
}
