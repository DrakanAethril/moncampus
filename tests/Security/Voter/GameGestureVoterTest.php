<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\GameEntry;
use App\Entity\User;
use App\Security\Voter\GameGestureVoter;
use App\Service\Game\GameRuleCatalog;

/**
 * The three doors of a teacher's gesture, and the two asymmetries that carry the design.
 *
 * A gesture is somebody's signed statement about a student. Withdrawing it under their name is not
 * on offer, so CANCEL and RESPOND belong to the author - **with no `ROLE_STAFF` bypass**, only the
 * administrator, who has to be able to unblock a departed teacher's line. And CONTEST belongs to
 * the student it was addressed to and to nobody else: a teacher contesting on their behalf would
 * empty the seven days of any meaning.
 */
class GameGestureVoterTest extends VoterTestCase
{
    private function gesture(?User $author, User $student, string $ruleCode = GameRuleCatalog::RECOGNITION_GESTURE_MALUS): GameEntry
    {
        $entry = $this->createStub(GameEntry::class);
        $entry->method('getAuthor')->willReturn($author);
        $entry->method('getStudent')->willReturn($student);
        $entry->method('getRuleCode')->willReturn($ruleCode);

        return $entry;
    }

    private function identified(User $user, int $id): User
    {
        // The Voter compares ids, so the fixtures have to carry one - two users with a null id
        // would compare equal and every test would pass for the wrong reason.
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    public function testTheAuthorWithdrawsAndAnswersTheirOwnGesture(): void
    {
        $teacher = $this->identified($this->user(['ROLE_USER', 'ROLE_TEACHER'], 'author'), 1);
        $student = $this->identified($this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student'), 2);
        $voter = new GameGestureVoter();

        $this->assertGranted($voter, $teacher, $this->gesture($teacher, $student), GameGestureVoter::CANCEL);
        $this->assertGranted($voter, $teacher, $this->gesture($teacher, $student), GameGestureVoter::RESPOND);
    }

    public function testAColleagueDoesNotWithdrawSomebodyElsesGestureAndNeitherDoesStaff(): void
    {
        $author = $this->identified($this->user(['ROLE_USER', 'ROLE_TEACHER'], 'author'), 1);
        $student = $this->identified($this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student'), 2);
        $colleague = $this->identified($this->user(['ROLE_USER', 'ROLE_TEACHER'], 'colleague'), 3);
        $staff = $this->identified($this->user(['ROLE_USER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'], 'staff'), 4);
        $voter = new GameGestureVoter();

        $this->assertDenied($voter, $colleague, $this->gesture($author, $student), GameGestureVoter::CANCEL);
        $this->assertDenied($voter, $staff, $this->gesture($author, $student), GameGestureVoter::CANCEL, 'there is no implicit staff bypass here');
        $this->assertDenied($voter, $staff, $this->gesture($author, $student), GameGestureVoter::RESPOND);
    }

    public function testAnAdministratorCanUnblockADepartedTeachersLine(): void
    {
        $author = $this->identified($this->user(['ROLE_USER', 'ROLE_TEACHER'], 'author'), 1);
        $student = $this->identified($this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student'), 2);
        $admin = $this->identified($this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin'), 5);

        $this->assertGranted(new GameGestureVoter(), $admin, $this->gesture($author, $student), GameGestureVoter::CANCEL);
    }

    public function testOnlyTheStudentAddressedContestsTheirOwnGesture(): void
    {
        $author = $this->identified($this->user(['ROLE_USER', 'ROLE_TEACHER'], 'author'), 1);
        $student = $this->identified($this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student'), 2);
        $other = $this->identified($this->user(['ROLE_USER', 'ROLE_STUDENT'], 'other'), 6);
        $admin = $this->identified($this->user(['ROLE_USER', 'ROLE_ADMIN'], 'admin'), 5);
        $voter = new GameGestureVoter();

        $this->assertGranted($voter, $student, $this->gesture($author, $student), GameGestureVoter::CONTEST);
        $this->assertDenied($voter, $other, $this->gesture($author, $student), GameGestureVoter::CONTEST);
        $this->assertDenied($voter, $author, $this->gesture($author, $student), GameGestureVoter::CONTEST, 'a teacher does not contest on a student behalf');
        $this->assertDenied($voter, $admin, $this->gesture($author, $student), GameGestureVoter::CONTEST, 'not even an administrator - the seven days are the student own');
    }

    public function testItStaysOutOfEveryLedgerLineThatIsNotAGesture(): void
    {
        $author = $this->identified($this->user(['ROLE_USER', 'ROLE_TEACHER'], 'author'), 1);
        $student = $this->identified($this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student'), 2);
        $voter = new GameGestureVoter();

        // A council mention, an attendance line, a work credit: none of them is withdrawable or
        // contestable, and a Voter that answered here would be deciding something that is not its own.
        foreach ([GameRuleCatalog::RECOGNITION_COUNCIL, GameRuleCatalog::ATTENDANCE_CLEAN, GameRuleCatalog::WORK_ON_TIME] as $code) {
            $this->assertAbstains($voter, $author, $this->gesture($author, $student, $code), GameGestureVoter::CANCEL, $code);
        }

        $this->assertAbstains($voter, $author, new \stdClass(), GameGestureVoter::CANCEL);
    }

    public function testAnAnonymousVisitorIsRefused(): void
    {
        $author = $this->identified($this->user(['ROLE_USER', 'ROLE_TEACHER'], 'author'), 1);
        $student = $this->identified($this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student'), 2);

        $this->assertDenied(new GameGestureVoter(), null, $this->gesture($author, $student), GameGestureVoter::CONTEST);
    }
}
