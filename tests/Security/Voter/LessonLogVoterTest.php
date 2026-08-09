<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\LessonLogVoter;

/**
 * Reading a cahier de texte follows the program's visibility; writing in it is the teacher of that
 * very session. The asymmetry is the whole point: an enrolled student reads, only the teacher edits.
 */
class LessonLogVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff, bool $programVisible): LessonLogVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);
        $checker->method('isProgramVisible')->willReturn($programVisible);

        return new LessonLogVoter($checker);
    }

    private function session(?User $teacher): LessonSession
    {
        $session = $this->createStub(LessonSession::class);
        $session->method('getTeacher')->willReturn($teacher);
        $session->method('getProgram')->willReturn($this->createStub(Program::class));

        return $session;
    }

    public function testStaffReadAndWriteEverything(): void
    {
        $session = $this->session($this->user(['ROLE_TEACHER'], 'owner'));

        $this->assertGranted($this->voter(true, false), $this->user(['ROLE_ADMIN']), $session, LessonLogVoter::VIEW);
        $this->assertGranted($this->voter(true, false), $this->user(['ROLE_ADMIN']), $session, LessonLogVoter::EDIT);
    }

    public function testOnlyTheSessionTeacherEdits(): void
    {
        $teacher = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');
        $session = $this->session($teacher);

        $this->assertGranted($this->voter(false, true), $teacher, $session, LessonLogVoter::EDIT);
        $this->assertDenied($this->voter(false, true), $other, $session, LessonLogVoter::EDIT);
    }

    public function testViewingFollowsProgramVisibility(): void
    {
        $session = $this->session($this->user(['ROLE_TEACHER'], 'owner'));
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');

        $this->assertGranted($this->voter(false, true), $student, $session, LessonLogVoter::VIEW);
        $this->assertDenied($this->voter(false, false), $student, $session, LessonLogVoter::VIEW);
    }
}
