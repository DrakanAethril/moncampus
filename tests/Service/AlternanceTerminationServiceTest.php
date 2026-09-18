<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\UfaActivityType;
use App\Repository\InternshipTutorLinkRepository;
use App\Service\AlternanceModalityAssigner;
use App\Service\AlternanceTerminationService;
use App\Service\UfaActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Terminating an alternance is two facts at once - the alternance goes quiet, and the student stops
 * being an alternant - and the pair is the point: a stamped date with the modality tag left behind
 * leaves a student the platform still counts as an alternant with nothing behind it.
 */
class AlternanceTerminationServiceTest extends TestCase
{
    public function testTerminatingStampsTheDateUntagsTheStudentAndJournalsIt(): void
    {
        [$tutorLink, $program, $student] = $this->alternance();
        $actor = $this->createStub(User::class);

        $modalityAssigner = $this->createMock(AlternanceModalityAssigner::class);
        $modalityAssigner->expects($this->once())->method('removeTag')->with($program, $student);

        $recorder = $this->createMock(UfaActivityRecorder::class);
        $recorder->expects($this->once())->method('record')
            ->with(UfaActivityType::AlternanceTerminated, $tutorLink, $actor);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $service = new AlternanceTerminationService(
            $entityManager,
            $this->tutorLinkRepository([$tutorLink]),
            $modalityAssigner,
            $recorder,
        );

        $this->assertTrue($service->terminate($tutorLink, $actor));
        $this->assertTrue($tutorLink->isTerminated());
        $this->assertSame($actor, $tutorLink->getInactivatedBy());
    }

    public function testTerminatingAnAlreadyTerminatedAlternanceChangesNothing(): void
    {
        [$tutorLink] = $this->alternance();
        $alreadyTerminatedOn = new \DateTimeImmutable('2026-03-12');
        $tutorLink->setInactiveDate($alreadyTerminatedOn);

        $modalityAssigner = $this->createMock(AlternanceModalityAssigner::class);
        $modalityAssigner->expects($this->never())->method('removeTag');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $service = new AlternanceTerminationService(
            $entityManager,
            $this->tutorLinkRepository([$tutorLink]),
            $modalityAssigner,
            $this->createStub(UfaActivityRecorder::class),
        );

        $this->assertFalse($service->terminate($tutorLink, $this->createStub(User::class)));
        $this->assertSame($alreadyTerminatedOn, $tutorLink->getInactiveDate());
    }

    // The tag belongs to the (Program, student) pair, not to one contract: a student holding a
    // second live alternance on the same formation is still an alternant.
    public function testTheModalityTagSurvivesWhileAnotherLiveAlternanceNeedsIt(): void
    {
        [$tutorLink, $program, $student] = $this->alternance();
        $otherLink = new InternshipTutorLink($program);
        $otherLink->setStudent($student);

        $modalityAssigner = $this->createMock(AlternanceModalityAssigner::class);
        $modalityAssigner->expects($this->never())->method('removeTag');

        $service = new AlternanceTerminationService(
            $this->createStub(EntityManagerInterface::class),
            $this->tutorLinkRepository([$tutorLink, $otherLink]),
            $modalityAssigner,
            $this->createStub(UfaActivityRecorder::class),
        );

        $this->assertTrue($service->terminate($tutorLink, $this->createStub(User::class)));
        $this->assertTrue($tutorLink->isTerminated());
    }

    // A classmate's live alternance is not this student's: it must not hold the tag in place.
    public function testAnotherStudentsLiveAlternanceDoesNotHoldTheTagInPlace(): void
    {
        [$tutorLink, $program, $student] = $this->alternance();
        $classmateLink = new InternshipTutorLink($program);
        $classmateLink->setStudent($this->createStub(User::class));

        $modalityAssigner = $this->createMock(AlternanceModalityAssigner::class);
        $modalityAssigner->expects($this->once())->method('removeTag')->with($program, $student);

        $service = new AlternanceTerminationService(
            $this->createStub(EntityManagerInterface::class),
            $this->tutorLinkRepository([$tutorLink, $classmateLink]),
            $modalityAssigner,
            $this->createStub(UfaActivityRecorder::class),
        );

        $service->terminate($tutorLink, $this->createStub(User::class));
    }

    public function testResumingClearsTheDateAndTagsTheStudentBack(): void
    {
        [$tutorLink, $program, $student] = $this->alternance();
        $tutorLink->setInactiveDate(new \DateTimeImmutable('2026-03-12'));
        $tutorLink->setInactivatedBy($this->createStub(User::class));

        $modalityAssigner = $this->createMock(AlternanceModalityAssigner::class);
        $modalityAssigner->expects($this->once())->method('ensureTagged')->with($program, $student);

        $service = new AlternanceTerminationService(
            $this->createStub(EntityManagerInterface::class),
            $this->tutorLinkRepository([$tutorLink]),
            $modalityAssigner,
            $this->createStub(UfaActivityRecorder::class),
        );

        $this->assertTrue($service->resume($tutorLink, $this->createStub(User::class)));
        $this->assertFalse($tutorLink->isTerminated());
        $this->assertNull($tutorLink->getInactivatedBy());
    }

    /** @return array{InternshipTutorLink, Program, User} */
    private function alternance(): array
    {
        $program = $this->createStub(Program::class);
        $student = $this->createStub(User::class);

        $tutorLink = new InternshipTutorLink($program);
        $tutorLink->setStudent($student);

        return [$tutorLink, $program, $student];
    }

    /** @param list<InternshipTutorLink> $liveLinks */
    private function tutorLinkRepository(array $liveLinks): InternshipTutorLinkRepository
    {
        $repository = $this->createStub(InternshipTutorLinkRepository::class);
        $repository->method('findAllActiveForProgram')->willReturn($liveLinks);

        return $repository;
    }
}
