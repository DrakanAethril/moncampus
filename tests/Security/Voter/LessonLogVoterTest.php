<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Security\LessonLogEditors;
use App\Security\StructureAccessChecker;
use App\Security\Voter\LessonLogVoter;

/**
 * Reading a cahier de texte follows the program's visibility; writing in it is for whoever actually
 * delivers the séance, and nobody else - staff included.
 *
 * The asymmetry is the whole point, and it now runs both ways: an enrolled student reads and does
 * not write, and a head of studies reads and does not write either. A cahier de texte is an
 * administrative record of what one teacher did with one group, so the two doors that open on it
 * are the teacher standing in that créneau and their co-animator - see App\Security\LessonLogEditors
 * for what « co-animator » means and its own test for how it is measured.
 */
class LessonLogVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff, bool $programVisible, bool $mayEdit = false): LessonLogVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);
        $checker->method('isProgramVisible')->willReturn($programVisible);

        $editors = $this->createStub(LessonLogEditors::class);
        $editors->method('mayEdit')->willReturn($mayEdit);

        return new LessonLogVoter($checker, $editors);
    }

    private function session(?User $teacher): LessonSession
    {
        $session = $this->createStub(LessonSession::class);
        $session->method('getTeacher')->willReturn($teacher);
        $session->method('getProgram')->willReturn($this->createStub(Program::class));

        return $session;
    }

    public function testStaffReadEverythingAndWriteNothing(): void
    {
        $session = $this->session($this->user(['ROLE_TEACHER'], 'owner'));
        $admin = $this->user(['ROLE_ADMIN']);

        $this->assertGranted($this->voter(true, false), $admin, $session, LessonLogVoter::VIEW);
        // The one thing this screen must not let a colleague do is sign someone else's register.
        $this->assertDenied($this->voter(true, false), $admin, $session, LessonLogVoter::EDIT);
    }

    public function testStaffWhoDeliverTheSeanceStillWriteInIt(): void
    {
        // Losing the bypass must not lock out a head of studies who actually teaches the créneau:
        // they are refused as staff and let in as its teacher, like anyone else. Which is to say the
        // role is not consulted at all on EDIT - only App\Security\LessonLogEditors is.
        $lead = $this->user(['ROLE_USER', 'ROLE_STAFF'], 'lead');

        $this->assertGranted($this->voter(true, false, mayEdit: true), $lead, $this->session($lead), LessonLogVoter::EDIT);
    }

    public function testWritingIsWhollyDecidedByWhoDeliversTheSeance(): void
    {
        // Program visibility is what opens reading, and it must not leak into writing: a colleague
        // of the class sees the séance and is still refused the pen.
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');
        $session = $this->session($this->user(['ROLE_TEACHER'], 'owner'));

        $this->assertDenied($this->voter(false, true, mayEdit: false), $other, $session, LessonLogVoter::EDIT);
    }

    public function testACoAnimatorEditsWithoutHoldingTheCreneau(): void
    {
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'other');
        $session = $this->session($this->user(['ROLE_TEACHER'], 'owner'));

        $this->assertGranted($this->voter(false, true, mayEdit: true), $other, $session, LessonLogVoter::EDIT);
    }

    public function testViewingFollowsProgramVisibility(): void
    {
        $session = $this->session($this->user(['ROLE_TEACHER'], 'owner'));
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');

        $this->assertGranted($this->voter(false, true), $student, $session, LessonLogVoter::VIEW);
        $this->assertDenied($this->voter(false, false), $student, $session, LessonLogVoter::VIEW);
    }
}
