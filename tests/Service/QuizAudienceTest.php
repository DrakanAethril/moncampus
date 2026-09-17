<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\ProgramStudentOptionRepository;
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
     * @param list<User>                 $optionStudents   what the option holds, per the platform's own link rows
     * @param array<string, list<Option>> $optionsByStudent the options each student is enrolled in, by username
     */
    private function audience(array $optionStudents = [], array $optionsByStudent = []): QuizAudience
    {
        $repository = $this->createStub(ProgramStudentOptionRepository::class);
        $repository->method('findStudentsForProgramAndOptions')->willReturn($optionStudents);
        $repository->method('findOptionsForStudent')->willReturnCallback(
            static fn (Program $program, User $student): array => $optionsByStudent[$student->getUsername()] ?? [],
        );

        return new QuizAudience($repository);
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

    private function enrol(Program $program, string $username): User
    {
        $student = new User($username);
        $program->addStudent($student);

        return $student;
    }
}
