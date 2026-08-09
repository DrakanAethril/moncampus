<?php

namespace App\Tests\Security\Voter;

use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SequenceTemplateVoter;

/**
 * A sequence in the library belongs to its author; staff may edit any.
 *
 * Two independent doors - staff, or ownership - so both are pinned, along with the case where
 * neither applies.
 */
class SequenceTemplateVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff): SequenceTemplateVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        return new SequenceTemplateVoter($checker);
    }

    private function subject(?User $owner): SequenceTemplate
    {
        $subject = $this->createStub(SequenceTemplate::class);
        $subject->method('getTeacher')->willReturn($owner);

        return $subject;
    }

    public function testOwnerEditsWithoutBeingStaff(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted($this->voter(false), $owner, $this->subject($owner), SequenceTemplateVoter::EDIT);
    }

    public function testStaffEditsSomeoneElsesRow(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $this->subject($owner), SequenceTemplateVoter::EDIT);
    }

    public function testAnotherTeacherIsDenied(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false), $other, $this->subject($owner), SequenceTemplateVoter::EDIT);
        $this->assertDenied($this->voter(false), null, $this->subject($owner), SequenceTemplateVoter::EDIT);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $this->assertAbstains($this->voter(true), $this->user(), $this->subject(null), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), SequenceTemplateVoter::EDIT);
    }
}
