<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\EcoParcours;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\EcoParcoursVoter;

/**
 * An e-CO parcours is edited by staff, by the teacher who created it, or by a colleague it is
 * shared with.
 *
 * Independent doors - staff, creation, sharing - so each is pinned, along with the case where
 * none applies.
 */
class EcoParcoursVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff): EcoParcoursVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        return new EcoParcoursVoter($checker);
    }

    private function subject(?User $owner): EcoParcours
    {
        return new EcoParcours($owner ?? $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'nobody'));
    }

    public function testOwnerEditsWithoutBeingStaff(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted($this->voter(false), $owner, $this->subject($owner), EcoParcoursVoter::EDIT);
    }

    public function testStaffEditsSomeoneElsesRow(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $this->subject($owner), EcoParcoursVoter::EDIT);
    }

    public function testAnotherTeacherIsDenied(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false), $other, $this->subject($owner), EcoParcoursVoter::EDIT);
        $this->assertDenied($this->voter(false), null, $this->subject($owner), EcoParcoursVoter::EDIT);
    }

    public function testAColleagueItIsSharedWithEditsLikeItsCreator(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_ECO'], 'owner');
        $colleague = $this->user(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_ECO'], 'colleague');
        $parcours = $this->subject($owner);

        $this->assertDenied($this->voter(false), $colleague, $parcours, EcoParcoursVoter::EDIT);

        $parcours->shareWith($colleague);
        $this->assertGranted($this->voter(false), $colleague, $parcours, EcoParcoursVoter::EDIT);

        $parcours->unshareWith($colleague);
        $this->assertDenied($this->voter(false), $colleague, $parcours, EcoParcoursVoter::EDIT);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $this->assertAbstains($this->voter(true), $this->user(), $this->subject(null), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), EcoParcoursVoter::EDIT);
    }
}
