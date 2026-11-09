<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\ReminderBatchStep;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipReminderBatchItemRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceReminderBoard;
use App\Service\ReminderBatch\ReminderBatchTarget;
use App\Service\ReminderBatch\ReminderWaiting;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The board is what the reminder batches take and what the dashboard banner announces, so what is
 * pinned here is who waits, on which step, and for which bilan rank - and that the banner falls
 * silent once a running batch holds them.
 */
class AlternanceReminderBoardTest extends TestCase
{
    /** @var array<int, array<int, true>> */
    private array $signedTutorEvaluations = [];
    /** @var array<int, array<int, true>> */
    private array $signedStudentEvaluations = [];
    /** @var array<int, array<int, true>> */
    private array $closedSupervisorEvaluations = [];
    /** @var array<int, array{tutor: bool, student: bool, center: bool}> */
    private array $engagements = [];
    /** @var list<InternshipEvaluationPeriod> */
    private array $periods = [];
    /** @var list<InternshipTutorLink> */
    private array $tutorLinks = [];
    /** @var array<string, true> */
    private array $queued = [];

    private const array SIGNED = ['tutor' => true, 'student' => true, 'center' => true];

    public function testAnUnsignedEngagementWaitsOnTheTutorThenOnTheAlternant(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10), $this->tutorLink(2, 20), $this->tutorLink(3, 30)];
        $this->engagements = [
            2 => ['tutor' => true, 'student' => false, 'center' => false],
            // The centre's signature alone: nobody a reminder can reach.
            3 => ['tutor' => true, 'student' => true, 'center' => false],
        ];

        self::assertSame(['engagement_tutor', 'engagement_student'], $this->keys($this->board()->waiting([$this->program()])));
    }

    public function testABilanIsNamedByItsRankInTheFormation(): void
    {
        $this->periods = [$this->period(1), $this->period(2)];
        // Everything of bilan 1 is in, nothing of bilan 2.
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->engagements = [1 => self::SIGNED];
        $this->signedTutorEvaluations = [1 => [1 => true]];
        $this->signedStudentEvaluations = [10 => [1 => true]];
        $this->closedSupervisorEvaluations = [1 => [1 => true]];

        $waiting = $this->board()->waiting([$this->program()]);

        self::assertSame(['period_tutor:2'], $this->keys($waiting));
        self::assertSame(2, $waiting[0]->period?->getId());
    }

    public function testTheAlternantsTurnComesOnceTheTutorSigned(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->engagements = [1 => self::SIGNED];
        $this->signedTutorEvaluations = [1 => [1 => true]];

        self::assertSame(['period_student:1'], $this->keys($this->board()->waiting([$this->program()])));
    }

    public function testABilanWaitingOnTheTeamSignatureAloneWaitsOnNobody(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->engagements = [1 => self::SIGNED];
        $this->signedTutorEvaluations = [1 => [1 => true]];
        $this->signedStudentEvaluations = [10 => [1 => true]];

        self::assertSame([], $this->board()->waiting([$this->program()]));
        self::assertFalse($this->board()->hasPendingReminders(null));
    }

    public function testABilanThatHasAlreadyClosedStillCountsAsSomethingToChase(): void
    {
        $this->periods = [$this->period(1, '2026-01-05', '2026-02-28')];
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->engagements = [1 => self::SIGNED];

        self::assertTrue($this->board()->hasPendingReminders(null));
    }

    public function testTerminatedAlternancesAreNeverChased(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10, terminated: true)];

        self::assertSame([], $this->board()->waiting([$this->program()]));
    }

    public function testATargetNarrowsTheBoardToItsOwnPeople(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10), $this->tutorLink(2, 20)];
        $this->engagements = [2 => self::SIGNED];

        $waiting = $this->board()->waiting([$this->program()], new ReminderBatchTarget(ReminderBatchStep::PeriodTutor, 1));

        self::assertCount(1, $waiting);
        self::assertSame(2, $waiting[0]->tutorLink->getId());
    }

    public function testTheBannerFallsSilentOnceARunningBatchHoldsEverybody(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->queued = ['1|engagement_tutor' => true];

        self::assertFalse($this->board()->hasPendingReminders(null));
    }

    public function testTheBannerOpensTheFormOnTheBusiestStep(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10), $this->tutorLink(2, 20), $this->tutorLink(3, 30)];
        $this->engagements = [2 => self::SIGNED, 3 => self::SIGNED];

        $unqueued = $this->board()->unqueued(null);

        self::assertSame(3, $unqueued['total']);
        self::assertSame('period_tutor:1', $unqueued['busiest']?->key());
    }

    /**
     * @param list<ReminderWaiting> $waiting
     *
     * @return list<string>
     */
    private function keys(array $waiting): array
    {
        return array_map(static fn (ReminderWaiting $entry): string => $entry->target->key(), $waiting);
    }

    private function program(): Program
    {
        $program = $this->createStub(Program::class);
        $program->method('getId')->willReturn(100);

        return $program;
    }

    private function board(): AlternanceReminderBoard
    {
        $schoolYearRepository = $this->createStub(SchoolYearRepository::class);
        $schoolYearRepository->method('findCurrentOrMostRecent')->willReturn($this->createStub(SchoolYear::class));

        $programRepository = $this->createStub(ProgramRepository::class);
        $programRepository->method('findAlternanceForSchoolYear')->willReturn([$this->program()]);

        $periodRepository = $this->createStub(InternshipEvaluationPeriodRepository::class);
        $periodRepository->method('findAllActiveForProgram')->willReturn($this->periods);

        $tutorLinkRepository = $this->createStub(InternshipTutorLinkRepository::class);
        $tutorLinkRepository->method('findAllActiveForProgram')->willReturn($this->tutorLinks);

        $engagementRepository = $this->createStub(InternshipLivretEngagementRepository::class);
        $engagementRepository->method('findSignatureStatesForProgram')->willReturn($this->engagements);

        $tutorEvaluationRepository = $this->createStub(InternshipTutorEvaluationRepository::class);
        $tutorEvaluationRepository->method('findSignedPairsForProgram')->willReturn($this->signedTutorEvaluations);

        $studentEvaluationRepository = $this->createStub(InternshipStudentEvaluationRepository::class);
        $studentEvaluationRepository->method('findSignedPairsForProgram')->willReturn($this->signedStudentEvaluations);

        $supervisorEvaluationRepository = $this->createStub(InternshipSupervisorEvaluationRepository::class);
        $supervisorEvaluationRepository->method('findClosedPairsForProgram')->willReturn($this->closedSupervisorEvaluations);

        $batchItemRepository = $this->createStub(InternshipReminderBatchItemRepository::class);
        $batchItemRepository->method('findQueuedKeys')->willReturn($this->queued);

        return new AlternanceReminderBoard(
            $schoolYearRepository,
            $programRepository,
            $periodRepository,
            $tutorLinkRepository,
            $engagementRepository,
            $tutorEvaluationRepository,
            $studentEvaluationRepository,
            $supervisorEvaluationRepository,
            $batchItemRepository,
            $this->resolver(),
        );
    }

    // The real resolver, fed by the index: what the board is for is that this decision is taken
    // in exactly one place, so a stub of it here would test nothing.
    private function resolver(): AlternancePeriodStatusResolver
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return new AlternancePeriodStatusResolver(
            $this->createStub(InternshipLivretEngagementRepository::class),
            $this->createStub(InternshipEvaluationPeriodRepository::class),
            $this->createStub(InternshipTutorEvaluationRepository::class),
            $this->createStub(InternshipStudentEvaluationRepository::class),
            $this->createStub(InternshipSupervisorEvaluationRepository::class),
            $translator,
        );
    }

    private function period(int $id, string $startDate = '2026-09-01', string $endDate = '2026-12-31'): InternshipEvaluationPeriod
    {
        $period = $this->createStub(InternshipEvaluationPeriod::class);
        $period->method('getId')->willReturn($id);
        $period->method('getStartDate')->willReturn(new \DateTimeImmutable($startDate));
        $period->method('getEndDate')->willReturn(new \DateTimeImmutable($endDate));
        $period->method('isPast')->willReturn(new \DateTimeImmutable($endDate) < new \DateTimeImmutable());

        return $period;
    }

    private function tutorLink(int $id, int $studentId, bool $terminated = false): InternshipTutorLink
    {
        $student = $this->createStub(User::class);
        $student->method('getId')->willReturn($studentId);

        $tutorLink = $this->createStub(InternshipTutorLink::class);
        $tutorLink->method('getId')->willReturn($id);
        $tutorLink->method('getStudent')->willReturn($student);
        $tutorLink->method('getTutor')->willReturn($this->createStub(User::class));
        $tutorLink->method('isTerminated')->willReturn($terminated);

        return $tutorLink;
    }
}
