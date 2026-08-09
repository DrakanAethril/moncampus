<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\SignupList;
use App\Entity\SignupListRegistration;
use App\Entity\User;
use App\Repository\SignupListRegistrationRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\AudienceTargetableVoter;
use App\Security\Voter\SignupListVoter;
use App\Service\AudienceResolver;

/**
 * Four attributes, each with its own rule, and they are easy to confuse:
 *
 *  - MANAGE        the creator, or staff
 *  - REGISTER      only if not already registered AND the list is addressed to you
 *  - UNREGISTER    only if you are registered - nothing else matters
 *  - VIEW_ROSTER   a manager, or anyone addressed when the roster is public
 *
 * The pairs that must not collapse into one another are pinned below: being in the audience is not
 * enough to manage, and a public roster does not make the list registrable twice.
 */
class SignupListVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff, bool $inAudience, bool $alreadyRegistered): SignupListVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        $resolver = $this->createStub(AudienceResolver::class);
        $resolver->method('isVisibleTo')->willReturn($inAudience);

        $registrations = $this->createStub(SignupListRegistrationRepository::class);
        $registrations->method('findOneForSignupListAndUser')
            ->willReturn($alreadyRegistered ? $this->createStub(SignupListRegistration::class) : null);

        return new SignupListVoter($checker, $resolver, $registrations);
    }

    private function list(?User $createdBy, bool $publicRoster = false): SignupList
    {
        $list = $this->createStub(SignupList::class);
        $list->method('getCreatedBy')->willReturn($createdBy);
        $list->method('isPublicRoster')->willReturn($publicRoster);

        return $list;
    }

    public function testCreatorAndStaffManageButAnAddressedUserDoesNot(): void
    {
        $creator = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'creator');
        $list = $this->list($creator);

        $this->assertGranted($this->voter(false, false, false), $creator, $list, SignupListVoter::MANAGE);
        $this->assertGranted($this->voter(true, false, false), $this->user(['ROLE_ADMIN'], 'staff'), $list, SignupListVoter::MANAGE);
        $this->assertDenied($this->voter(false, true, false), $this->user(['ROLE_STUDENT'], 'student'), $list, SignupListVoter::MANAGE);
    }

    public function testRegisteringNeedsTheAudienceAndNoExistingRegistration(): void
    {
        $list = $this->list($this->user(['ROLE_TEACHER'], 'creator'));
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');

        $this->assertGranted($this->voter(false, true, false), $student, $list, SignupListVoter::REGISTER);
        $this->assertDenied($this->voter(false, false, false), $student, $list, SignupListVoter::REGISTER, 'not addressed: no registration');
        $this->assertDenied($this->voter(false, true, true), $student, $list, SignupListVoter::REGISTER, 'already registered: no second registration');
    }

    /** Leaving only depends on being in: someone dropped from the audience must still get out. */
    public function testUnregisteringOnlyDependsOnBeingRegistered(): void
    {
        $list = $this->list($this->user(['ROLE_TEACHER'], 'creator'));
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');

        $this->assertGranted($this->voter(false, false, true), $student, $list, SignupListVoter::UNREGISTER);
        $this->assertDenied($this->voter(false, true, false), $student, $list, SignupListVoter::UNREGISTER);
    }

    public function testRosterIsManagersOnlyUnlessItIsPublic(): void
    {
        $creator = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'creator');
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');

        $this->assertGranted($this->voter(false, false, false), $creator, $this->list($creator), SignupListVoter::VIEW_ROSTER);
        $this->assertDenied($this->voter(false, true, false), $student, $this->list($creator, false), SignupListVoter::VIEW_ROSTER);
        $this->assertGranted($this->voter(false, true, false), $student, $this->list($creator, true), SignupListVoter::VIEW_ROSTER);
        $this->assertDenied($this->voter(false, false, false), $student, $this->list($creator, true), SignupListVoter::VIEW_ROSTER, 'public roster still requires being addressed');
    }

    public function testAnonymousAndForeignSubjectsAreRefused(): void
    {
        $list = $this->list(null);

        $this->assertDenied($this->voter(true, true, true), null, $list, SignupListVoter::MANAGE);
        $this->assertAbstains($this->voter(true, true, true), $this->user(), $list, AudienceTargetableVoter::VIEW);
        $this->assertAbstains($this->voter(true, true, true), $this->user(), new \stdClass(), SignupListVoter::MANAGE);
    }
}
