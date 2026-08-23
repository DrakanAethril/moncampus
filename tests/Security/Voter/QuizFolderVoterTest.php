<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\QuizFolder;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\QuizFolderVoter;

/**
 * A quiz folder is its owner's classement: the owner, or staff, and nobody else.
 *
 * One attribute and not the VIEW/EDIT pair QuizTemplateVoter carries - a folder is never shared, so
 * there is no third door to pin. What is worth pinning instead is that the *share* door of the quiz
 * next to it does not leak here: a colleague a quiz was shared with never touches the folder it sits
 * in, because that folder is not theirs.
 */
class QuizFolderVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff): QuizFolderVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        return new QuizFolderVoter($checker);
    }

    private function subject(User $owner): QuizFolder
    {
        $subject = $this->createStub(QuizFolder::class);
        $subject->method('getOwner')->willReturn($owner);

        return $subject;
    }

    public function testOwnerEditsWithoutBeingStaff(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertGranted($this->voter(false), $owner, $this->subject($owner), QuizFolderVoter::EDIT);
    }

    public function testStaffEditsSomeoneElsesFolder(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $this->subject($owner), QuizFolderVoter::EDIT);
    }

    public function testAnotherTeacherIsDenied(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');

        $this->assertDenied($this->voter(false), $other, $this->subject($owner), QuizFolderVoter::EDIT);
        $this->assertDenied($this->voter(false), null, $this->subject($owner), QuizFolderVoter::EDIT);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $owner = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');

        $this->assertAbstains($this->voter(true), $this->user(), $this->subject($owner), 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), QuizFolderVoter::EDIT);
    }
}
