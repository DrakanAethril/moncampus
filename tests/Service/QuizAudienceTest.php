<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\QuizAttemptRepository;
use App\Service\QuizAudience;
use PHPUnit\Framework\TestCase;

/**
 * Who a launched quiz is addressed to - the whole class, or the students of the one option it was
 * narrowed to at launch.
 *
 * The set matters twice over: it is what the student's hub is filtered on, and it is the denominator
 * of every « n / m » the teacher reads. A quiz limited to SLAM whose results screen still counted
 * SISR as absent would print a wrong number, not a partial one.
 */
class QuizAudienceTest extends TestCase
{
    private int $nextUserId = 0;

    public function testAnUnrestrictedQuizAddressesTheWholeClass(): void
    {
        $program = $this->program();
        $alice = $this->enrol($program, 'alice');
        $bob = $this->enrol($program, 'bob');

        $audience = $this->audience();
        $quiz = new QuizInstance($program, new User('prof'));

        self::assertSame([$alice, $bob], $audience->students($quiz));
        self::assertSame(2, $audience->count($quiz));
        self::assertTrue($audience->includes($quiz, $alice));
    }

    public function testANarrowedQuizAddressesTheOptionsStudentsOnly(): void
    {
        $program = $this->program();
        $alice = $this->enrol($program, 'alice');
        $bob = $this->enrol($program, 'bob');
        $slam = new Option('SLAM', 'SLAM', '#0d6efd');

        $quiz = new QuizInstance($program, new User('prof'));
        $quiz->setVisibilityOption($slam);

        $audience = $this->audience(optionStudents: [$alice], optionsByStudent: ['alice' => [$slam], 'bob' => []]);

        self::assertSame([$alice], $audience->students($quiz));
        self::assertSame(1, $audience->count($quiz));
        self::assertTrue($audience->includes($quiz, $alice));
        self::assertFalse($audience->includes($quiz, $bob));
    }

    /**
     * A ProgramStudentOption row outlives the enrolment it was written for: a student who has left
     * the class keeps it, and the results screen must not grow a line for somebody who is gone.
     */
    public function testAStudentWhoHasLeftTheClassIsNotAddressedAnyMore(): void
    {
        $program = $this->program();
        $gone = new User('gone');
        $slam = new Option('SLAM', 'SLAM', '#0d6efd');

        $quiz = new QuizInstance($program, new User('prof'));
        $quiz->setVisibilityOption($slam);

        self::assertSame([], $this->audience(optionStudents: [$gone])->students($quiz));
    }

    /** The narrowing applied to a whole list at once - what the student's hub is built on. */
    public function testReadableByKeepsTheUnrestrictedAndTheStudentsOwnOption(): void
    {
        $program = $this->program();
        $student = $this->enrol($program, 'alice');
        $slam = new Option('SLAM', 'SLAM', '#0d6efd');
        $sisr = new Option('SISR', 'SISR', '#198754');

        $open = new QuizInstance($program, new User('prof'));
        $mine = (new QuizInstance($program, new User('prof')))->setVisibilityOption($slam);
        $theirs = (new QuizInstance($program, new User('prof')))->setVisibilityOption($sisr);

        $readable = $this->audience(optionsByStudent: ['alice' => [$slam]])->readableBy($program, [$open, $mine, $theirs], $student);

        self::assertSame([$open, $mine], $readable);
    }

    /**
     * Narrowing a quiz somebody is sitting must not erase them from the teacher's screen: the row
     * would be gone while the copy is still being written, with no « Relancer » and no supervision
     * timeline to reach them by. They are counted too - the denominator is who the quiz *concerns*.
     */
    public function testSomebodyHoldingAnAttemptStaysOnTheRosterAfterNarrowing(): void
    {
        $program = $this->program();
        $slamStudent = $this->enrol($program, 'alice');
        $midQuiz = $this->enrol($program, 'bob');
        $slam = new Option('SLAM', 'SLAM', '#0d6efd');

        $quiz = $this->withId(new QuizInstance($program, new User('prof')), 42);
        $quiz->setVisibilityOption($slam);

        $audience = $this->audience(optionStudents: [$slamStudent], studentsWithAttempt: [$midQuiz]);

        self::assertSame([$slamStudent, $midQuiz], $audience->students($quiz));
        self::assertSame(2, $audience->count($quiz));
    }

    /** A leftover attempt from somebody who has left the class does not bring the row back either. */
    public function testAnAttemptFromSomebodyOutsideTheClassAddsNoRow(): void
    {
        $program = $this->program();
        $quiz = $this->withId(new QuizInstance($program, new User('prof')), 42);
        $quiz->setVisibilityOption(new Option('SLAM', 'SLAM', '#0d6efd'));

        self::assertSame([], $this->audience(studentsWithAttempt: [new User('gone')])->students($quiz));
    }

    /**
     * The narrowing decides who may discover and begin a quiz, never who has already begun it -
     * which is the whole reason « Visibilité » is editable after the launch
     * (App\Form\QuizInstanceEditType). A teacher narrowing a quiz to SLAM once the class has sat it
     * must not take the SISR students' own copies away from them.
     */
    public function testAStudentWhoHasAlreadySatItKeepsIt(): void
    {
        $program = $this->program();
        $student = $this->enrol($program, 'alice');
        $quiz = $this->withId(new QuizInstance($program, new User('prof')), 42);
        $quiz->setVisibilityOption(new Option('SISR', 'SISR', '#198754'));

        $audience = $this->audience(optionsByStudent: ['alice' => []], satInstanceIds: [42]);

        self::assertTrue($audience->includes($quiz, $student));
        self::assertSame([$quiz], $audience->readableBy($program, [$quiz], $student));
    }

    public function testAStudentWithNeitherTheOptionNorAnAttemptIsOutside(): void
    {
        $program = $this->program();
        $student = $this->enrol($program, 'alice');
        $quiz = $this->withId(new QuizInstance($program, new User('prof')), 42);
        $quiz->setVisibilityOption(new Option('SISR', 'SISR', '#198754'));

        $audience = $this->audience(optionsByStudent: ['alice' => []]);

        self::assertFalse($audience->includes($quiz, $student));
        self::assertSame([], $audience->readableBy($program, [$quiz], $student));
    }

    /**
     * @param list<User>                  $optionStudents   what the option holds, per the platform's own link rows
     * @param array<string, list<Option>> $optionsByStudent the options each student is enrolled in, by username
     * @param list<int>                   $satInstanceIds     the instances the reader already holds an attempt on
     * @param list<User>                  $studentsWithAttempt everybody holding an attempt on the quiz
     */
    private function audience(array $optionStudents = [], array $optionsByStudent = [], array $satInstanceIds = [], array $studentsWithAttempt = []): QuizAudience
    {
        $repository = $this->createStub(ProgramStudentOptionRepository::class);
        $repository->method('findStudentsForProgramAndOptions')->willReturn($optionStudents);
        $repository->method('findOptionsForStudent')->willReturnCallback(
            static fn (Program $program, User $student): array => $optionsByStudent[$student->getUsername()] ?? [],
        );

        $attempts = $this->createStub(QuizAttemptRepository::class);
        $attempts->method('findAttemptedInstanceIds')->willReturn($satInstanceIds);
        $attempts->method('findStudentsWithAttempt')->willReturn($studentsWithAttempt);
        $attempts->method('findForStudent')->willReturnCallback(
            static fn (QuizInstance $instance, User $student): array => \in_array((int) $instance->getId(), $satInstanceIds, true)
                ? [new QuizAttempt($instance, $student)]
                : [],
        );

        return new QuizAudience($repository, $attempts);
    }

    private function withId(QuizInstance $instance, int $id): QuizInstance
    {
        // Doctrine hands ids out; readableBy() keys the "already sat" set on them.
        (new \ReflectionProperty(QuizInstance::class, 'id'))->setValue($instance, $id);

        return $instance;
    }

    private function program(): Program
    {
        return new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            new Cohort('SIO-2', new Track('SIO', new Section('BTS'))),
            new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30')),
        );
    }

    /**
     * A student as Doctrine would hand one over - with an id, which students() keys its two sources
     * on to deduplicate them. Two id-less users would collide on the same key and one would vanish.
     */
    private function enrol(Program $program, string $username): User
    {
        $student = new User($username);
        (new \ReflectionProperty(User::class, 'id'))->setValue($student, ++$this->nextUserId);
        $program->addStudent($student);

        return $student;
    }
}
