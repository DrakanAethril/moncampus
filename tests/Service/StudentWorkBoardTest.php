<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\AssignmentExpectedProduction;
use App\Entity\AssignmentSubmission;
use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\StudentWorkState;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentDismissalRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\SelfAssessmentRepository;
use App\Service\AssignmentAudienceResolver;
use App\Service\AudioListenTracker;
use App\Service\StudentWorkBoard;
use App\Service\StudentWorkItem;
use App\Service\StudentWorkRow;
use PHPUnit\Framework\TestCase;

/**
 * The state of an assignment on the student's "Travail à faire" screen is read, never stored, and
 * everything the screen shows hangs off it: which of the two groups a row falls in, whether it is
 * still editable, whether a dismissed assignment stays visible or disappears, and what drops into
 * "Derniers travaux" as "Non rendu". That reading is pure logic against mocked repositories, which
 * is why it is worth pinning here (same reasoning as AlternancePeriodStatusResolverTest - this repo
 * verifies features in a browser, and unit-tests only the rules every screen depends on).
 */
class StudentWorkBoardTest extends TestCase
{
    private const string NOW = '2026-08-06 12:00:00';

    private User $student;
    private Program $program;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->student = new User('sio2-001');
        $this->program = new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            new Cohort('SIO-2', new Track('SIO', new Section('BTS'))),
            new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30')),
        );
    }

    public function testAnAssignmentDueLaterIsSimplyToDo(): void
    {
        $assignment = $this->assignment('2026-08-10 17:00', AssignmentNature::ToRevise);

        $this->assertSame(StudentWorkState::Todo, $this->stateOf($assignment));
    }

    public function testAnAssignmentPastItsDeadlineWithNothingDoneIsLate(): void
    {
        $assignment = $this->assignment('2026-07-31 17:00', AssignmentNature::ToRevise);

        $this->assertSame(StudentWorkState::Late, $this->stateOf($assignment));
    }

    public function testDeclaringAnAssignmentDoneBeforeItsDeadlineKeepsItListed(): void
    {
        $assignment = $this->assignment('2026-08-10 17:00', AssignmentNature::ToRevise);

        $state = $this->stateOf($assignment, doneAt: new \DateTimeImmutable('2026-08-05 09:00'));

        $this->assertSame(StudentWorkState::Submitted, $state);
    }

    public function testOnceItsDeadlineHasPassedAFinishedAssignmentLeavesTheListForTheHistory(): void
    {
        $assignment = $this->assignment('2026-08-01 17:00', AssignmentNature::ToRevise);

        $state = $this->stateOf($assignment, doneAt: new \DateTimeImmutable('2026-07-30 09:00'));

        $this->assertSame(StudentWorkState::Done, $state);
    }

    public function testAnAssignmentSetAsideWhileStillDueInTheFutureStaysVisible(): void
    {
        $assignment = $this->assignment('2026-08-10 17:00', AssignmentNature::ToRevise);

        $this->assertSame(StudentWorkState::Dismissed, $this->stateOf($assignment, dismissedProductionIds: [null]));
    }

    public function testAnAssignmentSetAsideOnceAlreadyLateDropsOffTheListEntirely(): void
    {
        $assignment = $this->assignment('2026-07-31 17:00', AssignmentNature::ToRevise);

        $this->assertSame([], $this->build($assignment, dismissedProductionIds: [null]));
    }

    public function testSettingOneDeadlineAsideLeavesTheOthersOfTheSameWorkDue(): void
    {
        $assignment = $this->assignment('2026-08-20 17:00', AssignmentNature::ToSubmit);
        $first = $this->production($assignment, 'Plan d\'adressage', 0);
        $first->setDueDate(new \DateTimeImmutable('2026-08-10 17:00'));
        $second = $this->production($assignment, 'Captures', 1);
        $second->setDueDate(new \DateTimeImmutable('2026-08-12 18:00'));

        $rows = $this->rows($assignment, dismissedProductionIds: [$first->getId()]);

        $this->assertCount(2, $rows);
        $this->assertSame(StudentWorkState::Dismissed, $rows[0]->state);
        $this->assertSame(StudentWorkState::Todo, $rows[1]->state);
    }

    public function testAWorkIsOnlySetAsideAsAWholeOnceEveryDeadlineOfItHasBeen(): void
    {
        $assignment = $this->assignment('2026-08-20 17:00', AssignmentNature::ToSubmit);
        $first = $this->production($assignment, 'Plan d\'adressage', 0);
        $first->setDueDate(new \DateTimeImmutable('2026-08-10 17:00'));
        $second = $this->production($assignment, 'Captures', 1);
        $second->setDueDate(new \DateTimeImmutable('2026-08-12 18:00'));

        $this->assertSame(StudentWorkState::Todo, $this->stateOf($assignment, dismissedProductionIds: [$first->getId()]));
        $this->assertSame(StudentWorkState::Dismissed, $this->stateOf($assignment, dismissedProductionIds: [$first->getId(), $second->getId()]));
    }

    public function testADeadlineSetAsideOnceAlreadyLateLeavesTheListOnItsOwn(): void
    {
        $assignment = $this->assignment('2026-08-20 17:00', AssignmentNature::ToSubmit);
        $assignment->setLateSubmissionAllowed(true);
        $late = $this->production($assignment, 'Plan d\'adressage', 0);
        $late->setDueDate(new \DateTimeImmutable('2026-08-03 17:00'));
        $this->production($assignment, 'Captures', 1)->setDueDate(new \DateTimeImmutable('2026-08-12 18:00'));

        $rows = $this->rows($assignment, dismissedProductionIds: [$late->getId()]);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-08-12 18:00', $rows[0]->dueDate->format('Y-m-d H:i'));
    }

    public function testASubmissionWindowClosedWithNothingHandedInReadsAsNotSubmitted(): void
    {
        $assignment = $this->assignment('2026-07-31 17:00', AssignmentNature::ToSubmit);

        $this->assertSame(StudentWorkState::Missed, $this->stateOf($assignment));
    }

    public function testAllowingLateSubmissionKeepsAMissedDeadlineMerelyLate(): void
    {
        $assignment = $this->assignment('2026-07-31 17:00', AssignmentNature::ToSubmit);
        $assignment->setLateSubmissionAllowed(true);

        $this->assertSame(StudentWorkState::Late, $this->stateOf($assignment));
    }

    public function testAnAssignmentIsOnlyFinishedOnceEveryExpectedProductionHasBeenHandedIn(): void
    {
        $assignment = $this->assignment('2026-08-10 17:00', AssignmentNature::ToSubmit);
        $first = $this->production($assignment, 'Plan d\'adressage', 0);
        $this->production($assignment, 'Compte rendu rédigé', 1);

        $state = $this->stateOf($assignment, submissions: [new AssignmentSubmission($assignment, $this->student, $first)]);

        $this->assertSame(StudentWorkState::Todo, $state);
    }

    public function testAnAssignmentIsFiledUnderTheEarliestDeadlineItStillOwes(): void
    {
        $assignment = $this->assignment('2026-08-20 17:00', AssignmentNature::ToSubmit);
        $first = $this->production($assignment, 'Plan d\'adressage', 0);
        $second = $this->production($assignment, 'Captures', 1);
        $second->setDueDate(new \DateTimeImmutable('2026-08-12 18:00'));

        // The one still owed carries the assignment's own deadline; handing it in should move the
        // row on to the next deadline rather than leave it where it was.
        $items = $this->build($assignment, submissions: [new AssignmentSubmission($assignment, $this->student, $first)]);

        $this->assertSame('2026-08-12 18:00', $items[0]->dueDate->format('Y-m-d H:i'));
    }

    public function testAQuizCountsAsDoneOnlyOnceItsTargetHasBeenReached(): void
    {
        $assignment = $this->assignment('2026-08-10 17:00', AssignmentNature::Quiz);
        $assignment->setMinimumScorePercent(70.0);

        $this->assertFalse($assignment->reachesMinimumScore(69.9));
        $this->assertTrue($assignment->reachesMinimumScore(70.0));
        $this->assertTrue($assignment->reachesMinimumScore(85.0));
    }

    public function testAQuizWithoutATargetIsFinishedByConcludingItAtAnyScore(): void
    {
        $assignment = $this->assignment('2026-08-10 17:00', AssignmentNature::Quiz);

        $this->assertTrue($assignment->reachesMinimumScore(0.0));
        $this->assertTrue($assignment->reachesMinimumScore(null));
    }

    private function assignment(string $dueDate, AssignmentNature $nature): Assignment
    {
        $assignment = new Assignment($this->program);
        $assignment->setTitle('Travail');
        $assignment->setNature($nature);
        $assignment->setDueDate(new \DateTimeImmutable($dueDate));
        $assignment->setAudienceType(AssignmentAudienceType::Program);
        $assignment->setVisibleAt(new \DateTimeImmutable('2026-07-01 08:00'));

        return $this->withId($assignment);
    }

    /**
     * The board keys everything by id, as it does on rows that came out of the database. Nothing is
     * persisted here, so the id is set by hand rather than left null - a null key would silently
     * collapse every lookup and make the whole class pass for the wrong reason.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function withId(object $entity): object
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $this->nextId++);

        return $entity;
    }

    private function production(Assignment $assignment, string $name, int $position): AssignmentExpectedProduction
    {
        $production = new AssignmentExpectedProduction($assignment);
        $production->setName($name);
        $production->setPosition($position);
        $assignment->addExpectedProduction($production);

        return $this->withId($production);
    }

    /**
     * @param list<AssignmentSubmission> $submissions
     * @param list<int|null>             $dismissedProductionIds expected productions set aside, null standing for the whole assignment
     */
    private function stateOf(Assignment $assignment, array $submissions = [], ?\DateTimeImmutable $doneAt = null, array $dismissedProductionIds = []): StudentWorkState
    {
        $items = $this->build($assignment, $submissions, $doneAt, $dismissedProductionIds);
        $this->assertCount(1, $items);

        return $items[0]->state;
    }

    /**
     * @param list<AssignmentSubmission> $submissions
     * @param list<int|null>             $dismissedProductionIds
     *
     * @return list<StudentWorkItem>
     */
    private function build(Assignment $assignment, array $submissions = [], ?\DateTimeImmutable $doneAt = null, array $dismissedProductionIds = []): array
    {
        return $this->board($assignment, $submissions, $doneAt, $dismissedProductionIds)
            ->build($this->student, new \DateTimeImmutable(self::NOW));
    }

    /**
     * The list as it is actually drawn: one line per deadline rather than one per assignment, which
     * is the only place a work asking for several dated productions can be read line by line.
     *
     * @param list<AssignmentSubmission> $submissions
     * @param list<int|null>             $dismissedProductionIds
     *
     * @return list<StudentWorkRow>
     */
    private function rows(Assignment $assignment, array $submissions = [], ?\DateTimeImmutable $doneAt = null, array $dismissedProductionIds = []): array
    {
        $now = new \DateTimeImmutable(self::NOW);
        $board = $this->board($assignment, $submissions, $doneAt, $dismissedProductionIds);

        return $board->rows($board->build($this->student, $now), $now);
    }

    /**
     * @param list<AssignmentSubmission> $submissions
     * @param list<int|null>             $dismissedProductionIds
     */
    private function board(Assignment $assignment, array $submissions, ?\DateTimeImmutable $doneAt, array $dismissedProductionIds): StudentWorkBoard
    {
        // Doctrine never runs here: the repositories are stubbed to answer for this one assignment,
        // which is enough - the board's whole job is the reading it makes of their answers.
        $programRepository = $this->createStub(ProgramRepository::class);
        $programRepository->method('findAllActiveForStudent')->willReturn([$this->program]);

        $assignmentRepository = $this->createStub(AssignmentRepository::class);
        $assignmentRepository->method('findVisibleForPrograms')->willReturn([$assignment]);

        $submissionRepository = $this->createStub(AssignmentSubmissionRepository::class);
        $submissionRepository->method('findByAssignmentIdForStudent')->willReturn([] === $submissions ? [] : [$assignment->getId() => $submissions]);

        $completionRepository = $this->createStub(AssignmentCompletionRepository::class);
        $completionRepository->method('findDoneDates')->willReturn(null === $doneAt ? [] : [$assignment->getId() => $doneAt]);

        $dismissalRepository = $this->createStub(AssignmentDismissalRepository::class);
        $dismissalRepository->method('findDismissedProductionIds')->willReturn([] === $dismissedProductionIds ? [] : [$assignment->getId() => $dismissedProductionIds]);

        $attemptRepository = $this->createStub(QuizAttemptRepository::class);
        $attemptRepository->method('findConcludedByInstanceForStudent')->willReturn([]);

        $selfAssessmentRepository = $this->createStub(SelfAssessmentRepository::class);
        $selfAssessmentRepository->method('findValidationDatesForStudent')->willReturn([]);

        $audienceResolver = $this->createStub(AssignmentAudienceResolver::class);
        $audienceResolver->method('isInAudience')->willReturn(true);

        // No assignment here is a listening, so nothing ever asks the tracker anything - it is only
        // there because the board takes one.
        $listenTracker = $this->createStub(AudioListenTracker::class);
        $listenTracker->method('completedAt')->willReturn(null);

        return new StudentWorkBoard(
            $programRepository,
            $assignmentRepository,
            $submissionRepository,
            $completionRepository,
            $dismissalRepository,
            $attemptRepository,
            $selfAssessmentRepository,
            $audienceResolver,
            $listenTracker,
        );
    }
}
