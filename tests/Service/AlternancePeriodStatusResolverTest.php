<?php

namespace App\Tests\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTeamEvaluation;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceStepStatus;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The first PHPUnit test class in this repo (see the feature's plan doc, §Verification) - this
 * app's working convention for feature verification is manual browser testing, not automated
 * tests; this class is added only because AlternancePeriodStatusResolver's ordering/lateness gate
 * is cheap to unit-test in isolation (pure logic against mocked repositories, no DB/HTTP needed)
 * and is the one piece of this feature's business logic every other screen depends on being
 * correct. Not a signal that this repo is migrating to a full test suite.
 */
class AlternancePeriodStatusResolverTest extends TestCase
{
    private InternshipLivretEngagementRepository&Stub $engagementRepository;
    private InternshipEvaluationPeriodRepository&Stub $periodRepository;
    private InternshipTutorEvaluationRepository&Stub $tutorEvaluationRepository;
    private InternshipStudentEvaluationRepository&Stub $studentEvaluationRepository;
    private InternshipTeamEvaluationRepository&Stub $teamEvaluationRepository;
    private InternshipSupervisorEvaluationRepository&Stub $supervisorEvaluationRepository;
    private AlternancePeriodStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->engagementRepository = $this->createStub(InternshipLivretEngagementRepository::class);
        $this->periodRepository = $this->createStub(InternshipEvaluationPeriodRepository::class);
        $this->tutorEvaluationRepository = $this->createStub(InternshipTutorEvaluationRepository::class);
        $this->studentEvaluationRepository = $this->createStub(InternshipStudentEvaluationRepository::class);
        $this->teamEvaluationRepository = $this->createStub(InternshipTeamEvaluationRepository::class);
        $this->supervisorEvaluationRepository = $this->createStub(InternshipSupervisorEvaluationRepository::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $this->resolver = new AlternancePeriodStatusResolver(
            $this->engagementRepository,
            $this->periodRepository,
            $this->tutorEvaluationRepository,
            $this->studentEvaluationRepository,
            $this->teamEvaluationRepository,
            $this->supervisorEvaluationRepository,
            $translator,
        );
    }

    public function testInactiveAlternanceShortCircuitsBeforeAnyGateIsChecked(): void
    {
        $tutorLink = $this->tutorLink(inactive: true);

        $engagementRepository = $this->createMock(InternshipLivretEngagementRepository::class);
        $engagementRepository->expects($this->never())->method('findOneForTutorLink');
        $this->engagementRepository = $engagementRepository;
        $this->resolver = new AlternancePeriodStatusResolver(
            $this->engagementRepository,
            $this->periodRepository,
            $this->tutorEvaluationRepository,
            $this->studentEvaluationRepository,
            $this->teamEvaluationRepository,
            $this->supervisorEvaluationRepository,
            $this->createStub(TranslatorInterface::class),
        );

        $status = $this->resolver->resolveCurrentStep($tutorLink);

        $this->assertSame(AlternanceStepStatus::STEP_INACTIVE, $status->step);
        $this->assertFalse($status->isLate);
    }

    public function testMissingEngagementRowIsTreatedAsNothingSignedYet(): void
    {
        $tutorLink = $this->tutorLink();
        $this->engagementRepository->method('findOneForTutorLink')->willReturn(null);

        $status = $this->resolver->resolveCurrentStep($tutorLink);

        $this->assertSame(AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR, $status->step);
        $this->assertTrue($status->isLate);
    }

    public function testEngagementStepsAreGatedInOrderTutorThenStudentThenCenter(): void
    {
        $tutorLink = $this->tutorLink();
        $engagement = $this->createStub(InternshipLivretEngagement::class);
        $engagement->method('getSignedTutorAt')->willReturn(new \DateTimeImmutable('2026-01-01'));
        $engagement->method('getSignedStudentAt')->willReturn(null);
        $this->engagementRepository->method('findOneForTutorLink')->willReturn($engagement);

        $status = $this->resolver->resolveCurrentStep($tutorLink);

        $this->assertSame(AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT, $status->step);
    }

    public function testEngagementCenterOnlyOpensOnceTutorAndStudentHaveBothSigned(): void
    {
        $tutorLink = $this->tutorLink();
        $engagement = $this->createStub(InternshipLivretEngagement::class);
        $engagement->method('getSignedTutorAt')->willReturn(new \DateTimeImmutable('2026-01-01'));
        $engagement->method('getSignedStudentAt')->willReturn(new \DateTimeImmutable('2026-01-02'));
        $engagement->method('getSignedCenterAt')->willReturn(null);
        $this->engagementRepository->method('findOneForTutorLink')->willReturn($engagement);

        $status = $this->resolver->resolveCurrentStep($tutorLink);

        $this->assertSame(AlternanceStepStatus::STEP_ENGAGEMENT_CENTER, $status->step);
    }

    public function testUnsignedTutorEvaluationOnAPastPeriodIsLate(): void
    {
        $tutorLink = $this->tutorLink();
        $this->engagementRepository->method('findOneForTutorLink')->willReturn($this->completeEngagement());

        $pastPeriod = $this->createStub(InternshipEvaluationPeriod::class);
        $pastPeriod->method('isPast')->willReturn(true);
        $this->periodRepository->method('findAllActiveForProgram')->willReturn([$pastPeriod]);
        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn(null);

        $status = $this->resolver->resolveCurrentStep($tutorLink);

        $this->assertSame(AlternanceStepStatus::STEP_TUTOR, $status->step);
        $this->assertTrue($status->isLate);
        $this->assertSame($pastPeriod, $status->period);
    }

    public function testStepAdvancesToStudentOnceTutorHasSigned(): void
    {
        $tutorLink = $this->tutorLink();
        $this->engagementRepository->method('findOneForTutorLink')->willReturn($this->completeEngagement());

        $period = $this->createStub(InternshipEvaluationPeriod::class);
        $period->method('isPast')->willReturn(false);
        $this->periodRepository->method('findAllActiveForProgram')->willReturn([$period]);

        $tutorEvaluation = $this->createStub(InternshipTutorEvaluation::class);
        $tutorEvaluation->method('isSigned')->willReturn(true);
        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn($tutorEvaluation);
        $this->studentEvaluationRepository->method('findOneForStudentAndEvaluationPeriod')->willReturn(null);

        $status = $this->resolver->resolveCurrentStep($tutorLink);

        $this->assertSame(AlternanceStepStatus::STEP_STUDENT, $status->step);
        $this->assertFalse($status->isLate);
    }

    public function testAllPeriodsClosedResolvesToClosedOnTheLastPeriod(): void
    {
        $tutorLink = $this->tutorLink();
        $this->engagementRepository->method('findOneForTutorLink')->willReturn($this->completeEngagement());

        $period1 = $this->createStub(InternshipEvaluationPeriod::class);
        $period1->method('isPast')->willReturn(true);
        $period2 = $this->createStub(InternshipEvaluationPeriod::class);
        $period2->method('isPast')->willReturn(true);
        $this->periodRepository->method('findAllActiveForProgram')->willReturn([$period1, $period2]);

        $signedTutorEvaluation = $this->createStub(InternshipTutorEvaluation::class);
        $signedTutorEvaluation->method('isSigned')->willReturn(true);
        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn($signedTutorEvaluation);

        $signedStudentEvaluation = $this->createStub(InternshipStudentEvaluation::class);
        $signedStudentEvaluation->method('isSigned')->willReturn(true);
        $this->studentEvaluationRepository->method('findOneForStudentAndEvaluationPeriod')->willReturn($signedStudentEvaluation);

        $signedTeamEvaluation = $this->createStub(InternshipTeamEvaluation::class);
        $signedTeamEvaluation->method('isSigned')->willReturn(true);
        $this->teamEvaluationRepository->method('findOneForStudentAndEvaluationPeriod')->willReturn($signedTeamEvaluation);

        $closedSupervisorEvaluation = $this->createStub(InternshipSupervisorEvaluation::class);
        $closedSupervisorEvaluation->method('isClosed')->willReturn(true);
        $this->supervisorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn($closedSupervisorEvaluation);

        $status = $this->resolver->resolveCurrentStep($tutorLink);

        $this->assertSame(AlternanceStepStatus::STEP_CLOSED, $status->step);
        $this->assertSame($period2, $status->period);
    }

    public function testResolveStepForPeriodReportsNotOpenedWhenAnEarlierPeriodIsStillOpen(): void
    {
        $tutorLink = $this->tutorLink();
        $this->engagementRepository->method('findOneForTutorLink')->willReturn($this->completeEngagement());

        $period1 = $this->createStub(InternshipEvaluationPeriod::class);
        $period1->method('isPast')->willReturn(false);
        $period2 = $this->createStub(InternshipEvaluationPeriod::class);
        $this->periodRepository->method('findAllActiveForProgram')->willReturn([$period1, $period2]);
        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn(null);

        $status = $this->resolver->resolveStepForPeriod($tutorLink, $period2);

        $this->assertSame(AlternanceStepStatus::STEP_NOT_OPENED, $status->step);
    }

    private function tutorLink(bool $inactive = false): InternshipTutorLink
    {
        $tutorLink = $this->createStub(InternshipTutorLink::class);
        $tutorLink->method('getInactiveDate')->willReturn($inactive ? new \DateTimeImmutable() : null);
        $tutorLink->method('getProgram')->willReturn($this->createStub(Program::class));
        $tutorLink->method('getStudent')->willReturn($this->createStub(User::class));
        $tutorLink->method('getTutor')->willReturn(null);
        $tutorLink->method('getSupervisor')->willReturn(null);

        return $tutorLink;
    }

    private function completeEngagement(): InternshipLivretEngagement
    {
        $engagement = $this->createStub(InternshipLivretEngagement::class);
        $engagement->method('getSignedTutorAt')->willReturn(new \DateTimeImmutable('2026-01-01'));
        $engagement->method('getSignedStudentAt')->willReturn(new \DateTimeImmutable('2026-01-02'));
        $engagement->method('getSignedCenterAt')->willReturn(new \DateTimeImmutable('2026-01-03'));

        return $engagement;
    }
}
