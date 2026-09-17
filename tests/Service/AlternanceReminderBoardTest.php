<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceReminderBoard;
use App\Service\AlternanceStepStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The board is what makes the dashboard banner and /ufa/reminders say the same thing, so what is
 * pinned here is the agreement itself: hasPendingReminders() is true exactly when build() has a
 * bilan, and a bilan is offered only when somebody on it is still to chase.
 */
class AlternanceReminderBoardTest extends TestCase
{
    /** @var array<int, array<int, true>> */
    private array $signedTutorEvaluations = [];
    /** @var array<int, array<int, true>> */
    private array $signedStudentEvaluations = [];
    /** @var array<int, array<int, true>> */
    private array $closedSupervisorEvaluations = [];
    /** @var array<int, true> */
    private array $fullySignedEngagements = [];
    /** @var list<InternshipEvaluationPeriod> */
    private array $periods = [];
    /** @var list<InternshipTutorLink> */
    private array $tutorLinks = [];

    public function testABilanIsOfferedOnlyWhenSomebodyOnItIsStillToChase(): void
    {
        $this->periods = [$this->period(1), $this->period(2)];
        // Everything of bilan 1 is in, nothing of bilan 2 - so only bilan 2 is worth opening.
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->fullySignedEngagements = [1 => true];
        $this->signedTutorEvaluations = [1 => [1 => true]];
        $this->signedStudentEvaluations = [10 => [1 => true]];
        $this->closedSupervisorEvaluations = [1 => [1 => true]];

        $bilans = $this->board()->build(null);

        $this->assertCount(1, $bilans);
        $this->assertSame(2, $bilans[0]['period']->getId());
        $this->assertSame(AlternanceStepStatus::STEP_TUTOR, $bilans[0]['rows'][0]['status']->step);
    }

    public function testNothingIsOfferedWhileTheEngagementIsNotSigned(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->fullySignedEngagements = [];

        $this->assertSame([], $this->board()->build(null));
        $this->assertFalse($this->board()->hasPendingReminders(null));
    }

    public function testABilanWaitingOnTheTeamSignatureAloneRaisesNoBanner(): void
    {
        // The step the relances screen cannot chase - no recipient for it - and therefore the one
        // the banner must not announce either.
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->fullySignedEngagements = [1 => true];
        $this->signedTutorEvaluations = [1 => [1 => true]];
        $this->signedStudentEvaluations = [10 => [1 => true]];

        $this->assertSame([], $this->board()->build(null));
        $this->assertFalse($this->board()->hasPendingReminders(null));
    }

    public function testABilanThatHasAlreadyClosedStillCountsAsSomethingToChase(): void
    {
        // The divergence the board was written for: the banner used to look at the bilans running
        // today only, so a late evaluation on a bilan that ended went unannounced.
        $this->periods = [$this->period(1, '2026-01-05', '2026-02-28')];
        $this->tutorLinks = [$this->tutorLink(1, 10)];
        $this->fullySignedEngagements = [1 => true];

        $this->assertTrue($this->board()->hasPendingReminders(null));
    }

    public function testTerminatedAlternancesAreNeverChased(): void
    {
        $this->periods = [$this->period(1)];
        $this->tutorLinks = [$this->tutorLink(1, 10, terminated: true)];
        $this->fullySignedEngagements = [1 => true];

        $this->assertFalse($this->board()->hasPendingReminders(null));
    }

    public function testEveryPendingBilanIsOfferedInStartDateOrder(): void
    {
        $this->periods = [$this->period(1), $this->period(2)];
        // One alternance is stuck on bilan 1, the other has closed it and is stuck on bilan 2.
        $this->tutorLinks = [$this->tutorLink(1, 10), $this->tutorLink(2, 20)];
        $this->fullySignedEngagements = [1 => true, 2 => true];
        $this->signedTutorEvaluations = [2 => [1 => true]];
        $this->signedStudentEvaluations = [20 => [1 => true]];
        $this->closedSupervisorEvaluations = [2 => [1 => true]];

        $bilans = $this->board()->build(null);

        $this->assertSame([1, 2], array_map(static fn (array $bilan): ?int => $bilan['period']->getId(), $bilans));
        $this->assertCount(1, $bilans[0]['rows']);
        $this->assertCount(1, $bilans[1]['rows']);
    }

    private function board(): AlternanceReminderBoard
    {
        $schoolYearRepository = $this->createStub(SchoolYearRepository::class);
        $schoolYearRepository->method('findCurrentOrMostRecent')->willReturn($this->createStub(SchoolYear::class));

        $programRepository = $this->createStub(ProgramRepository::class);
        $programRepository->method('findAlternanceForSchoolYear')->willReturn([$this->createStub(Program::class)]);

        $periodRepository = $this->createStub(InternshipEvaluationPeriodRepository::class);
        $periodRepository->method('findAllActiveForProgram')->willReturn($this->periods);

        $tutorLinkRepository = $this->createStub(InternshipTutorLinkRepository::class);
        $tutorLinkRepository->method('findAllActiveForProgram')->willReturn($this->tutorLinks);

        $engagementRepository = $this->createStub(InternshipLivretEngagementRepository::class);
        $engagementRepository->method('findFullySignedTutorLinkIdsForProgram')->willReturn($this->fullySignedEngagements);

        $tutorEvaluationRepository = $this->createStub(InternshipTutorEvaluationRepository::class);
        $tutorEvaluationRepository->method('findSignedPairsForProgram')->willReturn($this->signedTutorEvaluations);

        $studentEvaluationRepository = $this->createStub(InternshipStudentEvaluationRepository::class);
        $studentEvaluationRepository->method('findSignedPairsForProgram')->willReturn($this->signedStudentEvaluations);

        $supervisorEvaluationRepository = $this->createStub(InternshipSupervisorEvaluationRepository::class);
        $supervisorEvaluationRepository->method('findClosedPairsForProgram')->willReturn($this->closedSupervisorEvaluations);

        return new AlternanceReminderBoard(
            $schoolYearRepository,
            $programRepository,
            $periodRepository,
            $tutorLinkRepository,
            $engagementRepository,
            $tutorEvaluationRepository,
            $studentEvaluationRepository,
            $supervisorEvaluationRepository,
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
