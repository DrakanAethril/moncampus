<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\ContentShareAudience;

/**
 * A quiz in the library belongs to its author; staff may edit any.
 *
 * Two independent doors - staff, or ownership - so both are pinned, along with the case where
 * neither applies. VIEW adds a third, and only to VIEW: a colleague the quiz was shared with reads
 * it and never edits it (design/validated/content-sharing-between-teachers.md).
 */
class QuizTemplateVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff, bool $isShared = false): QuizTemplateVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        $audience = $this->createStub(ContentShareAudience::class);
        $audience->method('isSharedWith')->willReturn($isShared);

        return new QuizTemplateVoter($checker, $audience);
    }

    private function subject(?User $owner): QuizTemplate
    {
        $subject = $this->createStub(QuizTemplate::class);
        $subject->method('getTeacher')->willReturn($owner);

        return $subject;
    }

    public function testOwnerEditsWithoutBeingStaff(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted($this->voter(false), $owner, $this->subject($owner), QuizTemplateVoter::EDIT);
    }

    public function testStaffEditsSomeoneElsesRow(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $this->subject($owner), QuizTemplateVoter::EDIT);
    }

    public function testAnotherTeacherIsDenied(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false), $other, $this->subject($owner), QuizTemplateVoter::EDIT);
        $this->assertDenied($this->voter(false), null, $this->subject($owner), QuizTemplateVoter::EDIT);
    }

    public function testAColleagueItWasSharedWithReadsItButNeverEditsIt(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $reader = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'reader');

        $this->assertGranted($this->voter(false, true), $reader, $this->subject($owner), QuizTemplateVoter::VIEW);
        $this->assertDenied($this->voter(false, true), $reader, $this->subject($owner), QuizTemplateVoter::EDIT);
    }

    public function testAColleagueWithoutAShareReadsNothing(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false, false), $other, $this->subject($owner), QuizTemplateVoter::VIEW);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $this->assertAbstains($this->voter(true), $this->user(), $this->subject(null), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), QuizTemplateVoter::EDIT);
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), QuizTemplateVoter::VIEW);
    }
}
