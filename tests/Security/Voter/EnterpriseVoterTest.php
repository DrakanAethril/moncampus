<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Enterprise;
use App\Enum\Feature;
use App\Security\FeatureAccess;
use App\Security\Voter\EnterpriseVoter;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * The matrix of design/validated/vivier-entreprises.md §3, cell by cell: what everybody reads, what
 * the team reads once `enterprise_pool` is lit, what only ROLE_ADMIN does - and a test account
 * kept out of real employers.
 */
class EnterpriseVoterTest extends VoterTestCase
{
    private const array TEAM = [
        EnterpriseVoter::VIEW_CONTACTS, EnterpriseVoter::VIEW_TEACHER_CONTACTS, EnterpriseVoter::VIEW_STAFF_NOTES,
        EnterpriseVoter::WRITE_STAFF_NOTE, EnterpriseVoter::DECLARE_TEACHER_CONTACT, EnterpriseVoter::BROWSE_POOL,
    ];

    private const array ADMINISTRATOR = [EnterpriseVoter::VIEW_HOSTED_STUDENTS, EnterpriseVoter::FILTER_HOSTINGS, EnterpriseVoter::MANAGE];

    private function voter(bool $poolLit): EnterpriseVoter
    {
        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturnCallback(
            static fn (TokenInterface $token, array $attributes): bool => \in_array($attributes[0], $token->getUser()?->getRoles() ?? [], true),
        );
        $features = $this->createStub(FeatureAccess::class);
        $features->method('isEnabled')->willReturnCallback(static fn (Feature $feature): bool => Feature::EnterprisePool === $feature && $poolLit);

        return new EnterpriseVoter($decisions, $features);
    }

    public function testEverybodyReadsTheHostingsAndTheSharedContacts(): void
    {
        foreach (['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_ADMIN'] as $role) {
            $user = $this->user(['ROLE_USER', $role]);
            $this->assertGranted($this->voter(false), $user, new Enterprise('X'), EnterpriseVoter::VIEW, $role);
            $this->assertGranted($this->voter(false), $user, new Enterprise('X'), EnterpriseVoter::VIEW_SHARED_CONTACTS, $role);
        }
    }

    public function testAStudentNeverReadsTheTeamsPartNorTheAdministratorsOne(): void
    {
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT']);
        foreach ([...self::TEAM, ...self::ADMINISTRATOR] as $attribute) {
            $this->assertDenied($this->voter(true), $student, new Enterprise('X'), $attribute, $attribute);
        }
    }

    public function testTeachersAndStaffReadTheTeamsPartOnceThePoolIsLit(): void
    {
        foreach (['ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'] as $role) {
            $member = $this->user(['ROLE_USER', $role]);
            foreach (self::TEAM as $attribute) {
                $this->assertGranted($this->voter(true), $member, new Enterprise('X'), $attribute, $role.' '.$attribute);
                $this->assertDenied($this->voter(false), $member, new Enterprise('X'), $attribute, $role.' '.$attribute.' unlit');
            }
            foreach (self::ADMINISTRATOR as $attribute) {
                $this->assertDenied($this->voter(true), $member, null, $attribute, $role.' '.$attribute);
            }
        }
    }

    public function testTheAdministratorAloneSeesNamesFiltersOnKindsAndWrites(): void
    {
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN']);
        foreach ([...self::TEAM, ...self::ADMINISTRATOR] as $attribute) {
            $this->assertGranted($this->voter(false), $admin, new Enterprise('X'), $attribute, $attribute);
            $this->assertGranted($this->voter(false), $admin, null, $attribute, $attribute.' without subject');
        }
    }

    public function testATestAccountReachesTestEmployersOnly(): void
    {
        $tester = $this->user(['ROLE_USER', 'ROLE_ADMIN']);
        $tester->setTestUser(true);
        $real = new Enterprise('Réelle');
        $test = (new Enterprise('Test'))->setTestEnterprise(true);

        $this->assertDenied($this->voter(true), $tester, $real, EnterpriseVoter::VIEW);
        $this->assertGranted($this->voter(true), $tester, $test, EnterpriseVoter::VIEW);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $this->assertAbstains($this->voter(true), $this->user(), new Enterprise('X'), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), EnterpriseVoter::VIEW);
    }
}
