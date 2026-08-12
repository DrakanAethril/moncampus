<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LibraryResourceInstance;
use App\Entity\Program;
use App\Entity\SeanceInstance;
use App\Entity\SeancePhaseInstance;
use App\Entity\SequenceInstance;
use App\Repository\LibraryResourceInstanceViewRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Service\CourseSpaceBoard;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * The two rules the course space is made of: what a reader may see, and where a séance's resources
 * come from. Both are decided without touching a database, so both are tested without one.
 */
class CourseSpaceBoardTest extends TestCase
{
    public function testAStudentOnlyGetsPublishedSeances(): void
    {
        $sequence = $this->sequenceWith([
            $this->seance(visible: true),
            $this->seance(visible: false),
            $this->seance(visible: true),
        ]);

        self::assertCount(2, $this->board(readsUnpublished: false)->seancesFor($sequence));
    }

    /** Somebody has to be able to proof-read a séance before opening it. */
    public function testATeacherGetsThemAll(): void
    {
        $sequence = $this->sequenceWith([
            $this->seance(visible: true),
            $this->seance(visible: false),
        ]);

        self::assertCount(2, $this->board(readsUnpublished: true)->seancesFor($sequence));
    }

    /**
     * Phases are never shown, so their handouts would be unreachable for a student if they were not
     * lifted into the séance's own list.
     */
    public function testResourcesOfThePhasesAreFlattenedIntoTheSeance(): void
    {
        $seance = $this->seance(
            visible: true,
            ownResources: [$this->resource(true)],
            phaseResources: [$this->resource(true), $this->resource(true)],
        );

        self::assertCount(3, $this->board(readsUnpublished: false)->resourcesFor($seance));
    }

    public function testAStudentNeverSeesAResourceKeptForTheTeacher(): void
    {
        $seance = $this->seance(
            visible: true,
            ownResources: [$this->resource(true), $this->resource(false)],
            phaseResources: [$this->resource(false)],
        );

        self::assertCount(1, $this->board(readsUnpublished: false)->resourcesFor($seance));
        self::assertCount(3, $this->board(readsUnpublished: true)->resourcesFor($seance), 'the teacher edits that very flag');
    }

    private function board(bool $readsUnpublished): CourseSpaceBoard
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($readsUnpublished);
        $checker->method('isProgramTeacher')->willReturn($readsUnpublished);

        return new CourseSpaceBoard(
            $this->createStub(SequenceInstanceRepository::class),
            $this->createStub(LibraryResourceInstanceViewRepository::class),
            $checker,
        );
    }

    /** @param list<SeanceInstance> $seances */
    private function sequenceWith(array $seances): SequenceInstance
    {
        $sequence = $this->createStub(SequenceInstance::class);
        $sequence->method('getProgram')->willReturn($this->createStub(Program::class));
        $sequence->method('getSeanceInstances')->willReturn(new ArrayCollection($seances));

        return $sequence;
    }

    /**
     * @param list<LibraryResourceInstance> $ownResources
     * @param list<LibraryResourceInstance> $phaseResources
     */
    private function seance(bool $visible, array $ownResources = [], array $phaseResources = []): SeanceInstance
    {
        $phase = $this->createStub(SeancePhaseInstance::class);
        $phase->method('getLibraryResourceInstances')->willReturn(new ArrayCollection($phaseResources));

        $seance = $this->createStub(SeanceInstance::class);
        $seance->method('isVisibleToStudentsAt')->willReturn($visible);
        $seance->method('getProgram')->willReturn($this->createStub(Program::class));
        $seance->method('getLibraryResourceInstances')->willReturn(new ArrayCollection($ownResources));
        $seance->method('getSeancePhaseInstances')->willReturn(new ArrayCollection([$phase]));

        return $seance;
    }

    private function resource(bool $studentVisible): LibraryResourceInstance
    {
        $resource = $this->createStub(LibraryResourceInstance::class);
        $resource->method('isStudentVisible')->willReturn($studentVisible);

        return $resource;
    }
}
