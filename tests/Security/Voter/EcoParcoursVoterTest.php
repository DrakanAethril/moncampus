<?php

namespace App\Tests\Security\Voter;

use App\Entity\EcoParcours;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\EcoParcoursVoter;

/**
 * Un parcours e-CO se modifie par le personnel ou par l'enseignant qui l'a créé.
 *
 * Two independent doors - staff, or ownership - so both are pinned, along with the case where
 * neither applies.
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
        $subject = $this->createStub(EcoParcours::class);
        $subject->method('getTeacher')->willReturn($owner);

        return $subject;
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

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $this->assertAbstains($this->voter(true), $this->user(), $this->subject(null), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), EcoParcoursVoter::EDIT);
    }
}
