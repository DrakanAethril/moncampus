<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\SequenceFolder;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SequenceFolderVoter;

/**
 * A sequence folder is its owner's classement: the owner, or staff, and nobody else.
 *
 * It follows QuizFolderVoter rather than SurveyFolderVoter, and the difference is not cosmetic:
 * SequenceTemplateVoter::EDIT already lets staff into a colleague's séquence, so a folder Voter that
 * shut them out would leave them able to open a séquence and unable to see where it is filed. The
 * survey side is the opposite case, and its own test pins that.
 */
class SequenceFolderVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff): SequenceFolderVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        return new SequenceFolderVoter($checker);
    }

    private function subject(User $owner): SequenceFolder
    {
        $subject = $this->createStub(SequenceFolder::class);
        $subject->method('getOwner')->willReturn($owner);

        return $subject;
    }

    public function testOwnerEditsWithoutBeingStaff(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted($this->voter(false), $owner, $this->subject($owner), SequenceFolderVoter::EDIT);
    }

    public function testStaffEditsSomeoneElsesFolder(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $this->subject($owner), SequenceFolderVoter::EDIT);
    }

    public function testAnotherTeacherIsDenied(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false), $other, $this->subject($owner), SequenceFolderVoter::EDIT);
        $this->assertDenied($this->voter(false), null, $this->subject($owner), SequenceFolderVoter::EDIT);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertAbstains($this->voter(true), $this->user(), $this->subject($owner), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), SequenceFolderVoter::EDIT);
    }
}
