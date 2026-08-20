<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Progression;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\ProgressionVoter;

/**
 * A progression is edited by staff, by the teacher who owns it, or by a co-animator named on it.
 *
 * Three independent doors, so all three are pinned - along with the case that says the third one
 * is a door and not a corridor: another teacher of the same class, who is neither owner nor
 * co-animator, still gets nothing.
 */
class ProgressionVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff): ProgressionVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        return new ProgressionVoter($checker);
    }

    private function subject(?User $owner, ?User $coTeacher = null): Progression
    {
        $subject = $this->createStub(Progression::class);
        $subject->method('getTeacher')->willReturn($owner);
        $subject->method('isCoTeacher')->willReturnCallback(
            static fn (User $user): bool => null !== $coTeacher && $user === $coTeacher,
        );

        return $subject;
    }

    public function testOwnerEditsWithoutBeingStaff(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted($this->voter(false), $owner, $this->subject($owner), ProgressionVoter::EDIT);
    }

    public function testStaffEditsSomeoneElsesRow(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $this->subject($owner), ProgressionVoter::EDIT);
    }

    public function testCoTeacherEditsTheSharedPlan(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $coTeacher = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'co');

        $this->assertGranted($this->voter(false), $coTeacher, $this->subject($owner, $coTeacher), ProgressionVoter::EDIT);
    }

    public function testAnotherTeacherIsDenied(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $coTeacher = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'co');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false), $other, $this->subject($owner), ProgressionVoter::EDIT);
        // The co-animation link is per progression, never per class: a colleague teaching the same
        // class stays out of a plan nobody named them on.
        $this->assertDenied($this->voter(false), $other, $this->subject($owner, $coTeacher), ProgressionVoter::EDIT);
        $this->assertDenied($this->voter(false), null, $this->subject($owner), ProgressionVoter::EDIT);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $this->assertAbstains($this->voter(true), $this->user(), $this->subject(null), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), ProgressionVoter::EDIT);
    }
}
