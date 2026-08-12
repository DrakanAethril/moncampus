<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\LibraryResourceInstance;
use App\Entity\Program;
use App\Entity\SeanceInstance;
use App\Entity\SeancePhaseInstance;
use App\Entity\SequenceInstance;
use App\Entity\User;
use App\Enum\AccessConditionDisplay;
use App\Repository\LibraryResourceInstanceViewRepository;
use App\Repository\SequenceInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Service\AccessConditionGate;
use App\Service\AccessConditionHostKey;
use App\Service\AccessConditionVerdict;
use App\Service\AccessConditionVerdictMap;
use App\Service\CourseSpaceBoard;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The two rules the course space is made of: what a reader may see, and where a séance's resources
 * come from. Both are decided without touching a database, so both are tested without one.
 */
class CourseSpaceBoardTest extends TestCase
{
    private int $nextId = 1;

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

    /**
     * The corrigé released once the work is handed in - the use that justifies point 3 on its own.
     * Set to "Invisible", it is simply not among the séance's resources yet.
     */
    public function testAResourceHiddenByItsAccessConditionIsNotEvenListed(): void
    {
        $corrige = $this->resource(true);
        $corrige->method('getAccessConditionDisplay')->willReturn(AccessConditionDisplay::Hidden);

        $seance = $this->seance(visible: true, ownResources: [$this->resource(true), $corrige]);

        self::assertCount(1, $this->board(readsUnpublished: false, closed: [$corrige])->resourcesFor($seance, new User('sio2-001')));
    }

    /** Left on "Visible, verrouillé", the same corrigé stays listed - the student reads why. */
    public function testALockedResourceStaysListed(): void
    {
        $corrige = $this->resource(true);
        $corrige->method('getAccessConditionDisplay')->willReturn(AccessConditionDisplay::Locked);

        $seance = $this->seance(visible: true, ownResources: [$corrige]);

        self::assertCount(1, $this->board(readsUnpublished: false, closed: [$corrige])->resourcesFor($seance, new User('sio2-001')));
    }

    /**
     * @param list<LibraryResourceInstance|SequenceInstance> $closed hosts whose condition is not met
     */
    private function board(bool $readsUnpublished, array $closed = []): CourseSpaceBoard
    {
        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($readsUnpublished);
        $checker->method('isProgramTeacher')->willReturn($readsUnpublished);

        $verdicts = [];
        foreach ($closed as $host) {
            $verdicts[AccessConditionHostKey::of($host)] = new AccessConditionVerdict(false, [], ['motif']);
        }

        $gate = $this->createStub(AccessConditionGate::class);
        $gate->method('verdicts')->willReturn(new AccessConditionVerdictMap($verdicts));

        return new CourseSpaceBoard(
            $this->createStub(SequenceInstanceRepository::class),
            $this->createStub(LibraryResourceInstanceViewRepository::class),
            $checker,
            $gate,
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

    /** @return LibraryResourceInstance&Stub */
    private function resource(bool $studentVisible): LibraryResourceInstance
    {
        $resource = $this->createStub(LibraryResourceInstance::class);
        $resource->method('isStudentVisible')->willReturn($studentVisible);
        // An id of its own: verdicts are keyed by it, and two resources sharing a null id would
        // make one of them answer for the other.
        $resource->method('getId')->willReturn($this->nextId++);

        return $resource;
    }
}
