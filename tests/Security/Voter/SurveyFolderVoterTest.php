<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\SurveyFolder;
use App\Entity\User;
use App\Security\Voter\SurveyFolderVoter;

/**
 * A survey folder is its author's own classement: the owner alone.
 *
 * The test worth having here is the third one - **staff is denied**, where QuizFolderVoter grants
 * it. It follows SurveyVoter::EDIT, which has no staff bypass either, and pinning it is what stops
 * somebody "harmonising" the two folder Voters and handing staff the run of a library whose models
 * they may not open.
 */
class SurveyFolderVoterTest extends VoterTestCase
{
    private function subject(User $owner): SurveyFolder
    {
        $subject = $this->createStub(SurveyFolder::class);
        $subject->method('getOwner')->willReturn($owner);

        return $subject;
    }

    public function testOwnerEditsTheirOwnFolder(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted(new SurveyFolderVoter(), $owner, $this->subject($owner), SurveyFolderVoter::EDIT);
    }

    public function testStaffIsDeniedSomeoneElsesFolder(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertDenied(new SurveyFolderVoter(), $staff, $this->subject($owner), SurveyFolderVoter::EDIT);
    }

    public function testAnotherTeacherIsDenied(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied(new SurveyFolderVoter(), $other, $this->subject($owner), SurveyFolderVoter::EDIT);
        $this->assertDenied(new SurveyFolderVoter(), null, $this->subject($owner), SurveyFolderVoter::EDIT);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertAbstains(new SurveyFolderVoter(), $this->user(), $this->subject($owner), 'SOMETHING_ELSE');
        $this->assertAbstains(new SurveyFolderVoter(), $this->user(), new \stdClass(), SurveyFolderVoter::EDIT);
    }
}
