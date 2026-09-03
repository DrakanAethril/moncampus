<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\AssignmentSubmission;
use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SelfAssessment;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyTarget;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\AssignmentFollowUpStatus;
use App\Enum\AssignmentNature;
use App\Enum\AttemptStatus;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\AudioListenProgressRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\SelfAssessmentRepository;
use App\Repository\SurveyTargetRepository;
use App\Repository\VideoWatchProgressRepository;
use App\Service\AssignmentFollowUpBoard;
use App\Service\AssignmentFollowUpRow;
use PHPUnit\Framework\TestCase;

/**
 * The teacher's follow-up used to read deposits and nothing else, so twenty-two students who had
 * answered a quiz were listed « Non rendu » under a line correctly saying « 22 / 23 ont répondu ».
 * Every test here is one nature answering that it is read through its own proof - the rule is pure
 * logic against mocked repositories, exactly like StudentWorkBoardTest's.
 */
class AssignmentFollowUpBoardTest extends TestCase
{
    private User $marie;
    private User $paul;
    private Program $program;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->marie = $this->user('sio2-001');
        $this->paul = $this->user('sio2-002');
        $this->program = new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            new Cohort('SIO-2', new Track('SIO', new Section('BTS'))),
            new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30')),
        );
    }

    public function testAQuizIsReadThroughItsAttemptsAndNotThroughDeposits(): void
    {
        $assignment = $this->assignment(AssignmentNature::Quiz);
        $instance = $this->quizInstance($assignment);

        $rows = $this->rows($assignment, attempts: [$this->attempt($instance, $this->marie, 8, 10, '2026-09-01 10:12')]);

        $this->assertSame(AssignmentFollowUpStatus::Done, $rows[0]->status);
        $this->assertSame('2026-09-01 10:12', $rows[0]->doneAt?->format('Y-m-d H:i'));
        $this->assertSame(AssignmentFollowUpStatus::Pending, $rows[1]->status);
    }

    /** The words follow the nature: a quiz is answered, and « Non rendu » is what the bug said. */
    public function testAQuizSaysAnsweredAndNotSubmitted(): void
    {
        $assignment = $this->assignment(AssignmentNature::Quiz);
        $instance = $this->quizInstance($assignment);

        $rows = $this->rows($assignment, attempts: [$this->attempt($instance, $this->marie, 8, 10, '2026-09-01 10:12')]);

        $this->assertSame('assignmentFollowUpAnsweredLabel', $rows[0]->statusLabelKey);
        $this->assertSame('assignmentFollowUpNotAnsweredLabel', $rows[1]->statusLabelKey);
    }

    /**
     * Answering without reaching the teacher's threshold is neither: the student sat the quiz, and
     * a screen with only two states has to call that « Non répondu ».
     */
    public function testAnAttemptBelowTheThresholdIsNeitherDoneNorMissing(): void
    {
        $assignment = $this->assignment(AssignmentNature::Quiz);
        $assignment->setMinimumScorePercent(50.0);
        $instance = $this->quizInstance($assignment);

        $rows = $this->rows($assignment, attempts: [$this->attempt($instance, $this->marie, 3, 10, '2026-09-01 10:12')]);

        $this->assertSame(AssignmentFollowUpStatus::Insufficient, $rows[0]->status);
        $this->assertSame('assignmentFollowUpBelowThresholdLabel', $rows[0]->statusLabelKey);
        $this->assertSame(30.0, $rows[0]->getScorePercent());
    }

    /** "Reaching the target once is enough" - the rule StudentWorkBoard applies to the same pair. */
    public function testReachingTheThresholdOnceIsNotUndoneByAWeakerRetry(): void
    {
        $assignment = $this->assignment(AssignmentNature::Quiz);
        $assignment->setMinimumScorePercent(50.0);
        $instance = $this->quizInstance($assignment);

        $rows = $this->rows($assignment, attempts: [
            $this->attempt($instance, $this->marie, 9, 10, '2026-09-01 10:12'),
            $this->attempt($instance, $this->marie, 2, 10, '2026-09-02 11:30'),
        ]);

        $this->assertSame(AssignmentFollowUpStatus::Done, $rows[0]->status);
        $this->assertSame('2026-09-01 10:12', $rows[0]->doneAt?->format('Y-m-d H:i'));
    }

    public function testASurveyIsReadThroughItsFrozenTarget(): void
    {
        $assignment = $this->assignment(AssignmentNature::Survey);
        $campaign = new SurveyCampaign();
        $assignment->setSurveyCampaign($campaign);

        $responded = (new SurveyTarget($campaign, $this->marie))->setRespondedAt(new \DateTimeImmutable('2026-09-03 08:00'));

        $rows = $this->rows($assignment, surveyTargets: [$responded, new SurveyTarget($campaign, $this->paul)]);

        $this->assertSame(AssignmentFollowUpStatus::Done, $rows[0]->status);
        $this->assertSame('2026-09-03 08:00', $rows[0]->doneAt?->format('Y-m-d H:i'));
        $this->assertSame(AssignmentFollowUpStatus::Pending, $rows[1]->status);
    }

    /** A drafted estimate is not a handed-in one: only $validatedAt settles a self-assessment. */
    public function testASelfAssessmentOnlyCountsOnceValidated(): void
    {
        $assignment = $this->assignment(AssignmentNature::SelfAssessment);

        $validated = (new SelfAssessment($assignment, $this->marie))->validate();
        $draft = new SelfAssessment($assignment, $this->paul);

        $rows = $this->rows($assignment, selfAssessments: [
            (int) $this->marie->getId() => $validated,
            (int) $this->paul->getId() => $draft,
        ]);

        $this->assertSame(AssignmentFollowUpStatus::Done, $rows[0]->status);
        $this->assertSame(AssignmentFollowUpStatus::Pending, $rows[1]->status);
    }

    public function testAWorkSettledByADeclarationIsReadThroughTheDeclaration(): void
    {
        $assignment = $this->assignment(AssignmentNature::ToRevise);

        $rows = $this->rows($assignment, completions: [(int) $this->marie->getId() => new \DateTimeImmutable('2026-09-04 19:00')]);

        $this->assertSame(AssignmentFollowUpStatus::Done, $rows[0]->status);
        $this->assertSame('assignmentFollowUpDoneLabel', $rows[0]->statusLabelKey);
        $this->assertSame(AssignmentFollowUpStatus::Pending, $rows[1]->status);
        $this->assertSame('assignmentFollowUpNotDoneLabel', $rows[1]->statusLabelKey);
    }

    /** The one branch that predates the class, and the one that must not have moved. */
    public function testASubmissionStillReadsAsBefore(): void
    {
        $assignment = $this->assignment(AssignmentNature::ToSubmit);
        $submission = $this->submission($assignment, '2026-09-01 09:00');

        $rows = $this->rows($assignment, submissions: [(int) $this->marie->getId() => [$submission]]);

        $this->assertSame(AssignmentFollowUpStatus::Done, $rows[0]->status);
        $this->assertSame('assignmentSubmissionStatusSubmittedLabel', $rows[0]->statusLabelKey);
        $this->assertSame($submission, $rows[0]->getSubmission());
        $this->assertSame('assignmentSubmissionStatusMissingLabel', $rows[1]->statusLabelKey);
    }

    /** « En retard » is only ever said about a deposit - it is the only nature with its own window. */
    public function testADepositMadeAfterTheDeadlineReadsAsLate(): void
    {
        $assignment = $this->assignment(AssignmentNature::ToSubmit);
        $submission = $this->submission($assignment, '2026-09-20 09:00');

        $rows = $this->rows($assignment, submissions: [(int) $this->marie->getId() => [$submission]]);

        $this->assertSame(AssignmentFollowUpStatus::Late, $rows[0]->status);
        $this->assertSame('assignmentSubmissionStatusLateLabel', $rows[0]->statusLabelKey);
    }

    /**
     * @param list<QuizAttempt>                     $attempts
     * @param array<int, list<AssignmentSubmission>> $submissions
     * @param array<int, SelfAssessment>            $selfAssessments
     * @param array<int, \DateTimeImmutable>        $completions
     * @param list<SurveyTarget>                    $surveyTargets
     *
     * @return list<AssignmentFollowUpRow>
     */
    private function rows(
        Assignment $assignment,
        array $attempts = [],
        array $submissions = [],
        array $selfAssessments = [],
        array $completions = [],
        array $surveyTargets = [],
    ): array {
        $submissionRepository = $this->createStub(AssignmentSubmissionRepository::class);
        $submissionRepository->method('findAllByStudentIdForAssignment')->willReturn($submissions);

        $attemptRepository = $this->createStub(QuizAttemptRepository::class);
        $attemptRepository->method('findConcludedForInstance')->willReturn($attempts);

        $selfAssessmentRepository = $this->createStub(SelfAssessmentRepository::class);
        $selfAssessmentRepository->method('findByStudentIdForAssignment')->willReturn($selfAssessments);

        $completionRepository = $this->createStub(AssignmentCompletionRepository::class);
        $completionRepository->method('findDoneDatesByStudentIdForAssignment')->willReturn($completions);

        $surveyTargetRepository = $this->createStub(SurveyTargetRepository::class);
        $surveyTargetRepository->method('findAllFor')->willReturn($surveyTargets);

        $board = new AssignmentFollowUpBoard(
            $submissionRepository,
            $attemptRepository,
            $selfAssessmentRepository,
            $completionRepository,
            $surveyTargetRepository,
            $this->createStub(AudioListenProgressRepository::class),
            $this->createStub(VideoWatchProgressRepository::class),
        );

        return $board->rows($assignment, [$this->marie, $this->paul]);
    }

    private function assignment(AssignmentNature $nature): Assignment
    {
        $assignment = new Assignment($this->program);
        $assignment->setTitle('Réseaux - révisions');
        $assignment->setNature($nature);
        $assignment->setDueDate(new \DateTimeImmutable('2026-09-10 17:00'));
        $this->stampId($assignment);

        return $assignment;
    }

    private function quizInstance(Assignment $assignment): QuizInstance
    {
        $instance = new QuizInstance($this->program, $this->marie);
        $this->stampId($instance);
        $assignment->setQuizInstance($instance);

        return $instance;
    }

    private function attempt(QuizInstance $instance, User $student, float $correct, int $total, string $submittedAt): QuizAttempt
    {
        $attempt = new QuizAttempt($instance, $student);
        $attempt->setStatus(AttemptStatus::Termine);
        $attempt->setScore($correct, $total);
        $attempt->setSubmittedAt(new \DateTimeImmutable($submittedAt));
        $this->stampId($attempt);

        return $attempt;
    }

    /** $submittedAt is stamped at construction and has no setter - it is the deposit's own fact. */
    private function submission(Assignment $assignment, string $submittedAt): AssignmentSubmission
    {
        $submission = new AssignmentSubmission($assignment, $this->marie);
        (new \ReflectionProperty($submission, 'submittedAt'))->setValue($submission, new \DateTimeImmutable($submittedAt));
        $this->stampId($submission);

        return $submission;
    }

    private function user(string $username): User
    {
        $user = new User($username);
        $this->stampId($user);

        return $user;
    }

    /** Doctrine assigns identifiers on flush; nothing here is flushed, and every row is keyed by id. */
    private function stampId(object $entity): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $this->nextId++);
    }
}
