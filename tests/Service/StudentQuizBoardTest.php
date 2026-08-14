<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\AccessConditionDisplay;
use App\Enum\AccessConditionMode;
use App\Enum\AccessConditionType;
use App\Enum\QuizMode;
use App\Repository\QuizInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Service\AccessConditionEvaluator;
use App\Service\AccessConditionFactsLoader;
use App\Service\AccessConditionGate;
use App\Service\AccessConditionLabeler;
use App\Service\AccessConditionLeaf;
use App\Service\AccessConditionNameResolver;
use App\Service\AccessConditionNames;
use App\Service\AccessConditionTraces;
use App\Service\AccessConditionTree;
use App\Service\StudentAccessFacts;
use App\Service\StudentQuizBoard;
use PHPUnit\Framework\TestCase;

/**
 * The gate, applied to a student's quiz hub.
 *
 * A QuizInstance has been an AccessConditionHost since the conditions shipped, and the teacher's
 * screen has offered « Conditions d'accès » on it ever since - but neither the web hub nor the
 * mobile API ever asked the gate, so the rule was stored, displayed, and had no effect. A lock that
 * does not lock is worse than an absent one: the teacher believes they set it.
 *
 * This board is what both sides call now, for the same reason StudentWorkBoard exists: a rule
 * applied on one side only is how two screens come to announce different things.
 */
class StudentQuizBoardTest extends TestCase
{
    public function testAnOpenQuizIsListedAndPlayable(): void
    {
        $quiz = $this->quiz();
        $board = $this->board([$quiz]);
        $student = new User('sio2-001');

        $readable = $board->readableFor($this->program(), $student);

        self::assertSame([$quiz], $readable->instances);
        self::assertTrue($readable->verdicts->isOpen($quiz));
        self::assertTrue($board->isOpenFor($quiz, $student));
    }

    /** Locked: the row stays, greyed, with the way out written on it - it is not playable. */
    public function testALockedQuizStaysListedButRefusesToStart(): void
    {
        $quiz = $this->lockedQuiz();
        $board = $this->board([$quiz]);
        $student = new User('sio2-001');

        $readable = $board->readableFor($this->program(), $student);

        self::assertSame([$quiz], $readable->instances);
        self::assertFalse($readable->verdicts->isOpen($quiz));
        self::assertSame(['reason'], $readable->verdicts->reasonsFor($quiz));
        self::assertFalse($board->isOpenFor($quiz, $student));
    }

    /** Hidden: nothing at all, not even a greyed line - the remediation case. */
    public function testAHiddenQuizLeavesTheListEntirely(): void
    {
        $quiz = $this->lockedQuiz(AccessConditionDisplay::Hidden);
        $board = $this->board([$quiz]);

        self::assertSame([], $board->readableFor($this->program(), new User('sio2-001'))->instances);
    }

    /**
     * The rule that makes the whole thing safe to add: a teacher reads straight through, so putting
     * the gate on these screens can never take a quiz away from the person who launched it.
     */
    public function testATeacherReadsThroughEveryCondition(): void
    {
        $quiz = $this->lockedQuiz();
        $board = $this->board([$quiz], readsThrough: true);
        $teacher = new User('prof');

        self::assertSame([$quiz], $board->readableFor($this->program(), $teacher)->instances);
        self::assertTrue($board->isOpenFor($quiz, $teacher));
    }

    /**
     * A quiz already begun never closes back on the student who began it - otherwise a condition
     * that stops holding mid-attempt would strand them on a question they cannot leave.
     */
    public function testAQuizAlreadyBegunStaysOpen(): void
    {
        $quiz = $this->lockedQuiz();
        $traces = $this->createStub(AccessConditionTraces::class);
        $traces->method('startedHostKeys')->willReturn(['quiz_instance:42' => true]);

        self::assertTrue($this->board([$quiz], traces: $traces)->isOpenFor($quiz, new User('sio2-001')));
    }

    private function quiz(): QuizInstance
    {
        $quiz = new QuizInstance($this->program(), new User('prof'));
        $quiz->setName('Réseaux — VLAN');
        $quiz->setMode(QuizMode::Entrainement);

        // Doctrine hands ids out; a fresh entity has none, and the gate keys its map on them.
        $id = new \ReflectionProperty(QuizInstance::class, 'id');
        $id->setValue($quiz, 42);

        return $quiz;
    }

    private function lockedQuiz(AccessConditionDisplay $display = AccessConditionDisplay::Locked): QuizInstance
    {
        $quiz = $this->quiz();
        $quiz->setAccessConditionDisplay($display);
        $quiz->setAccessConditionTree(new AccessConditionTree(AccessConditionMode::All, [
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 99),
        ]));

        return $quiz;
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

    /** @param list<QuizInstance> $instances */
    private function board(array $instances, bool $readsThrough = false, ?AccessConditionTraces $traces = null): StudentQuizBoard
    {
        $repository = $this->createStub(QuizInstanceRepository::class);
        $repository->method('findActiveForProgram')->willReturn($instances);

        $factsLoader = $this->createStub(AccessConditionFactsLoader::class);
        $factsLoader->method('load')->willReturn(new StudentAccessFacts(new \DateTimeImmutable()));

        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($readsThrough);
        $checker->method('isProgramTeacher')->willReturn($readsThrough);

        $nameResolver = $this->createStub(AccessConditionNameResolver::class);
        $nameResolver->method('resolve')->willReturn(new AccessConditionNames([]));

        $labeler = $this->createStub(AccessConditionLabeler::class);
        $labeler->method('reasons')->willReturn(['reason']);

        if (null === $traces) {
            $noTrace = $this->createStub(AccessConditionTraces::class);
            $noTrace->method('startedHostKeys')->willReturn([]);
            $traces = $noTrace;
        }

        $gate = new AccessConditionGate($factsLoader, new AccessConditionEvaluator(), $nameResolver, $labeler, $traces, $checker);

        return new StudentQuizBoard($repository, $gate);
    }
}
