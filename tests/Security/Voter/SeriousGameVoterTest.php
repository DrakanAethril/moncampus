<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\User;
use App\Security\Voter\SeriousGameVoter;
use App\Service\SeriousGame\SeriousGameAccess;

/**
 * The routing of SeriousGameVoter (design/validated/serious-games.md §3.2): the decisions
 * themselves are pinned by SeriousGameRulesTest, so this pins that each attribute reaches the right
 * one with the right facts - and that the staff never read a student through their role.
 */
class SeriousGameVoterTest extends VoterTestCase
{
    public function testPlayAsksTheAccessFacts(): void
    {
        $game = new SeriousGame('observatoire', 'L’Observatoire');
        $student = $this->user(['ROLE_STUDENT'], 'student');

        $this->assertGranted($this->voter(play: true), $student, $game, SeriousGameVoter::PLAY);
        $this->assertDenied($this->voter(play: false), $student, $game, SeriousGameVoter::PLAY);
    }

    public function testATeacherReadsTheStudentsOfTheirClassesAndNoOther(): void
    {
        $teacher = $this->identified($this->user(['ROLE_TEACHER'], 'teacher'), 2);
        $student = $this->identified($this->user(['ROLE_STUDENT'], 'student'), 7);

        $this->assertGranted($this->voter(teachesStudent: true), $teacher, $student, SeriousGameVoter::READ_STUDENT);
        $this->assertDenied($this->voter(teachesStudent: false), $teacher, $student, SeriousGameVoter::READ_STUDENT);
    }

    public function testTheStaffReadsNoStudentAndTheAggregates(): void
    {
        $staff = $this->identified($this->user(['ROLE_STAFF'], 'staff'), 3);
        $student = $this->identified($this->user(['ROLE_STUDENT'], 'student'), 7);

        $this->assertDenied($this->voter(teachesStudent: true), $staff, $student, SeriousGameVoter::READ_STUDENT);
        $this->assertGranted($this->voter(), $staff, null, SeriousGameVoter::READ_AGGREGATE);
        $this->assertDenied($this->voter(), $staff, null, SeriousGameVoter::MANAGE);
    }

    public function testEveryTeacherReadsTheStatisticsAndTheStaffDoesNot(): void
    {
        $this->assertGranted($this->voter(), $this->user(['ROLE_TEACHER'], 'teacher'), null, SeriousGameVoter::READ_STATS);
        $this->assertGranted($this->voter(), $this->user(['ROLE_ADMIN'], 'admin'), null, SeriousGameVoter::READ_STATS);
        $this->assertDenied($this->voter(), $this->user(['ROLE_STAFF'], 'staff'), null, SeriousGameVoter::READ_STATS);
        $this->assertDenied($this->voter(), $this->user(['ROLE_STUDENT'], 'student'), null, SeriousGameVoter::READ_STATS);
    }

    public function testAStudentReadsThemselvesAndCollects(): void
    {
        $student = $this->identified($this->user(['ROLE_STUDENT'], 'student'), 7);

        $this->assertGranted($this->voter(), $student, $student, SeriousGameVoter::READ_STUDENT);
        $this->assertGranted($this->voter(), $student, null, SeriousGameVoter::COLLECT);
        $this->assertDenied($this->voter(), $this->user(['ROLE_TEACHER'], 'teacher'), null, SeriousGameVoter::COLLECT);
    }

    public function testAClassIsReadByItsTeacher(): void
    {
        $program = $this->createStub(Program::class);
        $teacher = $this->user(['ROLE_TEACHER'], 'teacher');

        $this->assertGranted($this->voter(teachesClass: true), $teacher, $program, SeriousGameVoter::READ_CLASS);
        $this->assertDenied($this->voter(teachesClass: false), $teacher, $program, SeriousGameVoter::READ_CLASS);
        $this->assertGranted($this->voter(teachesClass: false), $this->user(['ROLE_ADMIN'], 'admin'), $program, SeriousGameVoter::READ_CLASS);
    }

    public function testItStaysOutOfOtherSubjects(): void
    {
        $this->assertAbstains($this->voter(), $this->user(['ROLE_ADMIN']), new \stdClass(), SeriousGameVoter::PLAY);
        $this->assertAbstains($this->voter(), $this->user(['ROLE_ADMIN']), new \stdClass(), SeriousGameVoter::READ_STUDENT);
    }

    private function voter(bool $play = false, bool $teachesStudent = false, bool $teachesClass = false): SeriousGameVoter
    {
        $access = $this->createStub(SeriousGameAccess::class);
        $access->method('canPlay')->willReturn($play);
        $access->method('teachesAnActiveClassOf')->willReturn($teachesStudent);
        $access->method('teaches')->willReturn($teachesClass);

        return new SeriousGameVoter($access);
    }

    private function identified(User $user, int $id): User
    {
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
