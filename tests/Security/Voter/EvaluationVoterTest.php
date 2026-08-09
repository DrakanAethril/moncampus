<?php

namespace App\Tests\Security\Voter;

use App\Entity\Evaluation;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Security\Voter\EvaluationVoter;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * The most layered Voter of the set, and the one where a mistake is most costly: it decides whether
 * a student sees a mark.
 *
 * Four rules stack up - the topic's own teacher passes both attributes; MANAGE stops there and
 * refuses everyone else, staff included; staff read anything; a student reads only their own
 * program's evaluation, and only once it has become visible. That last clause is the one worth
 * pinning hardest: a grade published early is not recoverable.
 */
class EvaluationVoterTest extends VoterTestCase
{
    private function voter(bool $isStaff): EvaluationVoter
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($isStaff);

        return new EvaluationVoter($checker);
    }

    /**
     * @param list<User> $students
     */
    private function evaluation(?User $teacher, array $students = [], bool $visible = true): Evaluation
    {
        $program = $this->createStub(Program::class);
        $program->method('getStudents')->willReturn(new ArrayCollection($students));

        $topic = $this->createStub(Topic::class);
        $topic->method('getTeacher')->willReturn($teacher);
        $topic->method('getProgram')->willReturn($program);

        $evaluation = $this->createStub(Evaluation::class);
        $evaluation->method('getTopic')->willReturn($topic);
        $evaluation->method('isVisibleAt')->willReturn($visible);

        return $evaluation;
    }

    public function testTopicTeacherViewsAndManages(): void
    {
        $teacher = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $evaluation = $this->evaluation($teacher);

        $this->assertGranted($this->voter(false), $teacher, $evaluation, EvaluationVoter::VIEW);
        $this->assertGranted($this->voter(false), $teacher, $evaluation, EvaluationVoter::MANAGE);
    }

    /** Deliberate: staff read everything but do not grade in a teacher's place. */
    public function testStaffReadButDoNotManage(): void
    {
        $evaluation = $this->evaluation($this->user(['ROLE_TEACHER'], 'owner'));
        $staff = $this->user(['ROLE_USER', 'ROLE_ADMIN'], 'staff');

        $this->assertGranted($this->voter(true), $staff, $evaluation, EvaluationVoter::VIEW);
        $this->assertDenied($this->voter(true), $staff, $evaluation, EvaluationVoter::MANAGE);
    }

    public function testEnrolledStudentReadsOnlyOnceVisible(): void
    {
        $student = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'student');
        $teacher = $this->user(['ROLE_TEACHER'], 'owner');

        $this->assertGranted(
            $this->voter(false),
            $student,
            $this->evaluation($teacher, [$student], true),
            EvaluationVoter::VIEW,
        );
        $this->assertDenied(
            $this->voter(false),
            $student,
            $this->evaluation($teacher, [$student], false),
            EvaluationVoter::VIEW,
            'an evaluation not yet visible must stay hidden from the student',
        );
    }

    public function testStudentFromAnotherProgramNeverReads(): void
    {
        $enrolled = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'enrolled');
        $outsider = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'outsider');
        $evaluation = $this->evaluation($this->user(['ROLE_TEACHER'], 'owner'), [$enrolled], true);

        $this->assertDenied($this->voter(false), $outsider, $evaluation, EvaluationVoter::VIEW);
        $this->assertDenied($this->voter(false), null, $evaluation, EvaluationVoter::VIEW);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $evaluation = $this->evaluation(null);

        $this->assertAbstains($this->voter(true), $this->user(), $evaluation, 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), EvaluationVoter::VIEW);
    }
}
