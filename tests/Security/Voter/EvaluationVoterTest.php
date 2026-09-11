<?php

declare(strict_types=1);

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
 * Five rules stack up - a titulaire of the matière reads the whole of it, a co-titulaire's
 * evaluations included; MANAGE narrows that to the evaluation's own author, and refuses everyone
 * else, staff included; staff read anything; a student reads only their own program's evaluation,
 * and only once it has become visible. That last clause is the one worth pinning hardest: a grade
 * published early is not recoverable.
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
     * @param list<User> $titulaires  the matière's teachers - the first one authors the evaluation
     *                                unless $author says otherwise
     * @param list<User> $students
     * @param bool       $unauthored an evaluation not yet stamped, as the creation form asks about
     */
    private function evaluation(array $titulaires, array $students = [], bool $visible = true, ?User $author = null, bool $unauthored = false): Evaluation
    {
        $program = $this->createStub(Program::class);
        $program->method('getStudents')->willReturn(new ArrayCollection($students));

        $topic = $this->createStub(Topic::class);
        $topic->method('hasTeacher')->willReturnCallback(
            static fn (User $user): bool => \in_array($user, $titulaires, true),
        );
        $topic->method('getProgram')->willReturn($program);

        $evaluation = $this->createStub(Evaluation::class);
        $evaluation->method('getTopic')->willReturn($topic);
        $evaluation->method('getCreatedBy')->willReturn($unauthored ? null : ($author ?? ($titulaires[0] ?? null)));
        $evaluation->method('isVisibleAt')->willReturn($visible);

        return $evaluation;
    }

    public function testTopicTeacherViewsAndManages(): void
    {
        $teacher = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'owner');
        $evaluation = $this->evaluation([$teacher]);

        $this->assertGranted($this->voter(false), $teacher, $evaluation, EvaluationVoter::VIEW);
        $this->assertGranted($this->voter(false), $teacher, $evaluation, EvaluationVoter::MANAGE);
    }

    /**
     * The rule the whole multi-titulaire feature rests on: two teachers hold one matière, and each
     * writes only what they posed. Reading is shared - they are looking at the same carnet.
     */
    public function testCoTitulaireReadsTheColleaguesEvaluationButNeverWritesIt(): void
    {
        $author = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'author');
        $colleague = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'colleague');
        $evaluation = $this->evaluation([$author, $colleague], author: $author);

        $this->assertGranted($this->voter(false), $colleague, $evaluation, EvaluationVoter::VIEW);
        $this->assertDenied(
            $this->voter(false),
            $colleague,
            $evaluation,
            EvaluationVoter::MANAGE,
            'a co-titulaire reads the colleague\'s column and never rewrites it',
        );
    }

    /**
     * The controller asks MANAGE on a `new Evaluation` before stamping its author, so a null author
     * has to read as "mine, in the making" - otherwise nobody could create one at all.
     */
    public function testEvaluationBeingCreatedIsManageableByAnyTitulaire(): void
    {
        $teacher = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'titulaire');
        $other = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'outsider');
        $evaluation = $this->evaluation([$teacher], unauthored: true);

        $this->assertGranted($this->voter(false), $teacher, $evaluation, EvaluationVoter::MANAGE);
        $this->assertDenied($this->voter(false), $other, $evaluation, EvaluationVoter::MANAGE);
    }

    /** A teacher of the class who is not a titulaire of this matière is an outsider here. */
    public function testTeacherOfAnotherTopicNeitherReadsNorWrites(): void
    {
        $titulaire = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'titulaire');
        $stranger = $this->user(['ROLE_USER', 'ROLE_TEACHER'], 'stranger');
        $evaluation = $this->evaluation([$titulaire]);

        $this->assertDenied($this->voter(false), $stranger, $evaluation, EvaluationVoter::VIEW);
        $this->assertDenied($this->voter(false), $stranger, $evaluation, EvaluationVoter::MANAGE);
    }

    /** Deliberate: staff read everything but do not grade in a teacher's place. */
    public function testStaffReadButDoNotManage(): void
    {
        $evaluation = $this->evaluation([$this->user(['ROLE_TEACHER'], 'owner')]);
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
            $this->evaluation([$teacher], [$student], true),
            EvaluationVoter::VIEW,
        );
        $this->assertDenied(
            $this->voter(false),
            $student,
            $this->evaluation([$teacher], [$student], false),
            EvaluationVoter::VIEW,
            'an evaluation not yet visible must stay hidden from the student',
        );
    }

    public function testStudentFromAnotherProgramNeverReads(): void
    {
        $enrolled = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'enrolled');
        $outsider = $this->user(['ROLE_USER', 'ROLE_STUDENT'], 'outsider');
        $evaluation = $this->evaluation([$this->user(['ROLE_TEACHER'], 'owner')], [$enrolled], true);

        $this->assertDenied($this->voter(false), $outsider, $evaluation, EvaluationVoter::VIEW);
        $this->assertDenied($this->voter(false), null, $evaluation, EvaluationVoter::VIEW);
    }

    public function testForeignAttributesAndSubjectsAreLeftAlone(): void
    {
        $evaluation = $this->evaluation([]);

        $this->assertAbstains($this->voter(true), $this->user(), $evaluation, 'SOMETHING_ELSE');
        $this->assertAbstains($this->voter(true), $this->user(), new \stdClass(), EvaluationVoter::VIEW);
    }
}
