<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Service\AlternancePeriodWizardService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Covers what terminating an alternance does to the three wizards, since that gate is the whole
 * enforcement of « on ne demande plus rien au tuteur, à l'alternant ni au centre »: five wizard
 * actions across two portals read these methods and nothing else.
 */
class AlternancePeriodWizardServiceTest extends TestCase
{
    private InternshipLivretEngagementRepository&Stub $engagementRepository;
    private InternshipTutorEvaluationRepository&Stub $tutorEvaluationRepository;
    private InternshipStudentEvaluationRepository&Stub $studentEvaluationRepository;
    private InternshipSupervisorEvaluationRepository&Stub $supervisorEvaluationRepository;
    private AlternancePeriodWizardService $service;

    protected function setUp(): void
    {
        $this->engagementRepository = $this->createStub(InternshipLivretEngagementRepository::class);
        $this->tutorEvaluationRepository = $this->createStub(InternshipTutorEvaluationRepository::class);
        $this->studentEvaluationRepository = $this->createStub(InternshipStudentEvaluationRepository::class);
        $this->supervisorEvaluationRepository = $this->createStub(InternshipSupervisorEvaluationRepository::class);

        $this->service = new AlternancePeriodWizardService(
            $this->engagementRepository,
            $this->tutorEvaluationRepository,
            $this->studentEvaluationRepository,
            $this->supervisorEvaluationRepository,
        );
    }

    public function testEveryRoleIsReadOnlyOnATerminatedAlternance(): void
    {
        $tutorLink = $this->tutorLink(terminated: true);
        $period = $this->createStub(InternshipEvaluationPeriod::class);

        // Nothing is signed and no period is closed: read-only comes from the termination alone.
        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn(null);
        $this->studentEvaluationRepository->method('findOneForStudentAndEvaluationPeriod')->willReturn(null);
        $this->supervisorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn(null);

        $this->assertTrue($this->service->isTutorStepReadOnly($tutorLink, $period));
        $this->assertTrue($this->service->isStudentStepReadOnly($tutorLink, $period));
        $this->assertTrue($this->service->isSupervisorStepReadOnly($tutorLink, $period));
    }

    public function testARunningAlternanceWithNothingSignedIsWritableForEveryRole(): void
    {
        $tutorLink = $this->tutorLink();
        $period = $this->createStub(InternshipEvaluationPeriod::class);

        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn(null);
        $this->studentEvaluationRepository->method('findOneForStudentAndEvaluationPeriod')->willReturn(null);
        $this->supervisorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn(null);

        $this->assertFalse($this->service->isTutorStepReadOnly($tutorLink, $period));
        $this->assertFalse($this->service->isStudentStepReadOnly($tutorLink, $period));
        $this->assertFalse($this->service->isSupervisorStepReadOnly($tutorLink, $period));
    }

    public function testASignedRoleStaysReadOnlyWhateverTheTerminationSays(): void
    {
        $tutorLink = $this->tutorLink();
        $period = $this->createStub(InternshipEvaluationPeriod::class);

        $signedTutorEvaluation = $this->createStub(InternshipTutorEvaluation::class);
        $signedTutorEvaluation->method('isSigned')->willReturn(true);
        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn($signedTutorEvaluation);

        $signedStudentEvaluation = $this->createStub(InternshipStudentEvaluation::class);
        $signedStudentEvaluation->method('isSigned')->willReturn(true);
        $this->studentEvaluationRepository->method('findOneForStudentAndEvaluationPeriod')->willReturn($signedStudentEvaluation);

        $this->assertTrue($this->service->isTutorStepReadOnly($tutorLink, $period));
        $this->assertTrue($this->service->isStudentStepReadOnly($tutorLink, $period));
    }

    // Termination freezes the chain's reading instead of rewinding it: the period whose wizard was
    // reachable stays reachable, read-only, which is how staff still consult what was filled in.
    public function testTerminationDoesNotCloseTheStepsTheChainHadAlreadyOpened(): void
    {
        $tutorLink = $this->tutorLink(terminated: true);
        $period = $this->createStub(InternshipEvaluationPeriod::class);

        $signedTutorEvaluation = $this->createStub(InternshipTutorEvaluation::class);
        $signedTutorEvaluation->method('isSigned')->willReturn(true);
        $this->tutorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn($signedTutorEvaluation);

        $signedStudentEvaluation = $this->createStub(InternshipStudentEvaluation::class);
        $signedStudentEvaluation->method('isSigned')->willReturn(true);
        $this->studentEvaluationRepository->method('findOneForStudentAndEvaluationPeriod')->willReturn($signedStudentEvaluation);

        $this->assertTrue($this->service->isStudentStepOpen($tutorLink, $period));
        $this->assertTrue($this->service->isSupervisorStepOpen($tutorLink, $period));
    }

    public function testAClosedPeriodStaysReadOnlyForTheSupervisor(): void
    {
        $tutorLink = $this->tutorLink();
        $period = $this->createStub(InternshipEvaluationPeriod::class);

        $closedSupervisorEvaluation = $this->createStub(InternshipSupervisorEvaluation::class);
        $closedSupervisorEvaluation->method('isClosed')->willReturn(true);
        $this->supervisorEvaluationRepository->method('findOneForTutorLinkAndEvaluationPeriod')->willReturn($closedSupervisorEvaluation);

        $this->assertTrue($this->service->isSupervisorStepReadOnly($tutorLink, $period));
    }

    private function tutorLink(bool $terminated = false): InternshipTutorLink
    {
        $tutorLink = $this->createStub(InternshipTutorLink::class);
        $tutorLink->method('isTerminated')->willReturn($terminated);
        $tutorLink->method('getStudent')->willReturn($this->createStub(User::class));

        return $tutorLink;
    }
}
