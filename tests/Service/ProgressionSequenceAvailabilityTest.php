<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\SequenceInstance;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\ProgressionSequenceRepository;
use App\Repository\SequenceInstanceRepository;
use App\Service\ProgressionSequenceAvailability;
use PHPUnit\Framework\TestCase;

/**
 * Which of a class's séquences a progression may still take.
 *
 * The rule the screens got wrong: a séquence instantiated for a class is planned ONCE, by one
 * progression. Filtering on "the progression I am looking at doesn't carry it" left every other
 * progression of the same class offering it - and its rail listing it as "non affectée" - although
 * it was already planned elsewhere.
 */
class ProgressionSequenceAvailabilityTest extends TestCase
{
    public function testDropsInstancesAlreadyPlannedByAnyProgressionOfTheClass(): void
    {
        $program = $this->program();
        $teacher = new User('teacher');
        $free = $this->instance(1, $program, $teacher);
        $plannedElsewhere = $this->instance(2, $program, $teacher);

        $availability = $this->availability([$free, $plannedElsewhere], [2]);

        self::assertSame([$free], $availability->forTeacher($program, $teacher));
    }

    public function testAProgressionIsOfferedItsOwnTeachersFreeInstances(): void
    {
        // Narrowed to the progression's teacher, not to whoever is looking: a staff member opening
        // someone else's progression must see that teacher's séquences.
        $program = $this->program();
        $teacher = new User('teacher');
        $free = $this->instance(1, $program, $teacher);

        $availability = $this->availability([$free], []);

        self::assertSame([$free], $availability->forProgression($this->progression($program, $teacher)));
    }

    public function testAProgressionWithoutTeacherIsOfferedNothing(): void
    {
        $orphan = (new \ReflectionClass(Progression::class))->newInstanceWithoutConstructor();

        self::assertSame([], $this->availability([], [])->forProgression($orphan));
    }

    public function testInstanceOfAnotherClassIsNeverAvailable(): void
    {
        $teacher = new User('teacher');
        $progression = $this->progression($this->program(), $teacher);
        $foreign = $this->instance(1, $this->program(), $teacher);

        self::assertFalse($this->availability([], [])->isAvailable($progression, $foreign));
    }

    public function testInstanceOfAColleagueIsNeverAvailable(): void
    {
        $program = $this->program();
        $progression = $this->progression($program, new User('teacher'));
        $colleagues = $this->instance(1, $program, new User('teacher'));

        self::assertFalse($this->availability([], [])->isAvailable($progression, $colleagues));
    }

    public function testAnAlreadyPlannedInstanceIsRefusedEvenToItsOwnTeacher(): void
    {
        // The write side of the list: the id is the only thing a hand-built POST has to change, so
        // "already planned" is re-asked here rather than trusted from the <select>.
        $program = $this->program();
        $teacher = new User('teacher');
        $progression = $this->progression($program, $teacher);
        $instance = $this->instance(1, $program, $teacher);

        self::assertFalse($this->availability([], [1])->isAvailable($progression, $instance));
    }

    public function testAFreeInstanceOfTheOwnClassIsAvailable(): void
    {
        $program = $this->program();
        $teacher = new User('teacher');
        $progression = $this->progression($program, $teacher);
        $instance = $this->instance(1, $program, $teacher);

        self::assertTrue($this->availability([], [])->isAvailable($progression, $instance));
    }

    public function testTheCoAnimatorsOwnInstancesReachTheSharedPool(): void
    {
        // Lot 2 of design/validated/co-animation.md: the séquence pool follows the progression's
        // teachers, plural. Without this a séquence the co-animator instantiated from their own
        // template is invisible in the plan they are entitled to edit.
        $program = $this->program();
        $owner = new User('owner');
        $coTeacher = new User('co');
        $progression = $this->progression($program, $owner, $coTeacher);
        $theirs = $this->instance(1, $program, $coTeacher);

        $availability = $this->availability([$theirs], []);

        self::assertSame([$theirs], $availability->forProgression($progression));
        self::assertTrue($availability->isAvailable($progression, $theirs));
    }

    public function testAnInstanceAlreadyPlannedInTheClassStaysRefusedToTheCoAnimator(): void
    {
        // The rule lot 2 must NOT loosen: an instantiated séquence is planned once for the whole
        // class. Widening the pool to a second teacher widens WHOSE copies are offered, never how
        // many times one of them may be planned.
        $program = $this->program();
        $owner = new User('owner');
        $coTeacher = new User('co');
        $progression = $this->progression($program, $owner, $coTeacher);
        $plannedElsewhere = $this->instance(1, $program, $coTeacher);

        $availability = $this->availability([$plannedElsewhere], [1]);

        self::assertSame([], $availability->forProgression($progression));
        self::assertFalse($availability->isAvailable($progression, $plannedElsewhere));
    }

    public function testAnInstanceOfATeacherNamedOnNothingStaysRefused(): void
    {
        $program = $this->program();
        $progression = $this->progression($program, new User('owner'), new User('co'));
        $strangers = $this->instance(1, $program, new User('stranger'));

        self::assertFalse($this->availability([], [])->isAvailable($progression, $strangers));
    }

    /**
     * @param list<SequenceInstance> $instances
     * @param list<int>              $plannedIds
     */
    private function availability(array $instances, array $plannedIds): ProgressionSequenceAvailability
    {
        $instanceRepository = $this->createStub(SequenceInstanceRepository::class);
        $instanceRepository->method('findForProgramCreatedByAny')->willReturn($instances);

        $sequenceRepository = $this->createStub(ProgressionSequenceRepository::class);
        $sequenceRepository->method('findPlannedInstanceIdsForProgram')->willReturn($plannedIds);
        $sequenceRepository->method('isInstancePlanned')->willReturnCallback(
            static fn (SequenceInstance $instance): bool => \in_array((int) $instance->getId(), $plannedIds, true),
        );

        return new ProgressionSequenceAvailability($instanceRepository, $sequenceRepository);
    }

    private function program(): Program
    {
        return (new \ReflectionClass(Program::class))->newInstanceWithoutConstructor();
    }

    private function instance(int $id, Program $program, User $creator): SequenceInstance
    {
        $instance = new SequenceInstance($program, $creator);
        (new \ReflectionProperty($instance, 'id'))->setValue($instance, $id);

        return $instance;
    }

    private function progression(Program $program, User $teacher, ?User $coTeacher = null): Progression
    {
        // Both entities are built without their constructors: a Program registers itself in
        // collections a bare test object doesn't have, and the progression only ever reads its
        // Topic's Program back here.
        $topic = (new \ReflectionClass(Topic::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($topic, 'program'))->setValue($topic, $program);

        $progression = new Progression($topic, $teacher);
        if (null !== $coTeacher) {
            $progression->addCoTeacher($coTeacher);
        }

        return $progression;
    }
}
