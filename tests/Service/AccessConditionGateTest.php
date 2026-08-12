<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\AccessConditionDisplay;
use App\Enum\AccessConditionMode;
use App\Enum\AccessConditionType;
use App\Security\StructureAccessChecker;
use App\Service\AccessConditionEvaluator;
use App\Service\AccessConditionFactsLoader;
use App\Service\AccessConditionGate;
use App\Service\AccessConditionLabeler;
use App\Service\AccessConditionLeaf;
use App\Service\AccessConditionNameResolver;
use App\Service\AccessConditionNames;
use App\Service\AccessConditionTraces;
use App\Service\AccessConditionTree;
use App\Service\StudentAccessFacts;
use PHPUnit\Framework\TestCase;

/**
 * What the screens actually call. The decision itself is AccessConditionEvaluator's and is tested
 * on its own; what is checked here is everything the gate adds around it - the teacher's
 * short-circuit, the difference between a greyed row and an absent one, and the rule that an object
 * already begun never closes back on the student who began it.
 */
class AccessConditionGateTest extends TestCase
{
    /** "isStaff() / isProgramTeacher() ignorent les conditions" - the conception's third constraint. */
    public function testATeacherReadsStraightThroughEveryCondition(): void
    {
        $assignment = $this->lockedAssignment();
        $gate = $this->gate(readsThrough: true);

        self::assertTrue($gate->verdicts([$assignment], new User('sio2-001'))->isOpen($assignment));
    }

    public function testAStudentIsHeldByAnUnmetCondition(): void
    {
        $assignment = $this->lockedAssignment();
        $verdicts = $this->gate()->verdicts([$assignment], new User('sio2-001'));

        self::assertFalse($verdicts->isOpen($assignment));
        // Locked: the row stays on the screen, with the way out written on it.
        self::assertTrue($verdicts->isVisible($assignment));
        self::assertSame(['reason'], $verdicts->reasonsFor($assignment));
    }

    /** The remediation case: nothing at all until the condition holds, not even a greyed line. */
    public function testAHiddenObjectIsNotEvenVisible(): void
    {
        $assignment = $this->lockedAssignment(AccessConditionDisplay::Hidden);

        self::assertFalse($this->gate()->verdicts([$assignment], new User('sio2-001'))->isVisible($assignment));
    }

    public function testAnObjectWithNoConditionIsOpenAndCostsNoQuery(): void
    {
        $assignment = new Assignment($this->program());
        $loader = $this->createMock(AccessConditionFactsLoader::class);
        $loader->expects(self::never())->method('load');

        $gate = $this->gate(loader: $loader);

        self::assertTrue($gate->verdicts([$assignment], new User('sio2-001'))->isOpen($assignment));
    }

    /**
     * "Dès qu'une trace existe, l'objet reste accessible même si la condition n'est plus remplie" -
     * a student who starts a remediation, retakes the quiz and climbs back over the threshold must
     * not have the work vanish under their hands.
     */
    public function testAnObjectAlreadyBegunStaysOpen(): void
    {
        $assignment = $this->lockedAssignment();

        $traces = $this->createStub(AccessConditionTraces::class);
        $traces->method('startedHostKeys')->willReturn(['assignment:17' => true]);

        self::assertTrue($this->gate(traces: $traces)->verdicts([$assignment], new User('sio2-001'))->isOpen($assignment));
    }

    private function lockedAssignment(AccessConditionDisplay $display = AccessConditionDisplay::Locked): Assignment
    {
        $assignment = new Assignment($this->program());
        $assignment->setAccessConditionDisplay($display);
        $assignment->setAccessConditionTree(new AccessConditionTree(AccessConditionMode::All, [
            new AccessConditionLeaf(AccessConditionType::AssignmentDone, 99),
        ]));

        // Doctrine hands ids out; a fresh entity has none, and the gate keys its map on them.
        $id = new \ReflectionProperty(Assignment::class, 'id');
        $id->setValue($assignment, 17);

        return $assignment;
    }

    private function program(): Program
    {
        return new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            new Cohort('SIO-2', new Track('SIO', new Section('BTS'))),
            new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30')),
        );
    }

    private function gate(
        bool $readsThrough = false,
        ?AccessConditionFactsLoader $loader = null,
        ?AccessConditionTraces $traces = null,
    ): AccessConditionGate {
        $loader ??= $this->createStub(AccessConditionFactsLoader::class);
        $loader->method('load')->willReturn(new StudentAccessFacts(new \DateTimeImmutable()));

        $checker = $this->createStub(StructureAccessChecker::class);
        $checker->method('isStaff')->willReturn($readsThrough);
        $checker->method('isProgramTeacher')->willReturn($readsThrough);

        $nameResolver = $this->createStub(AccessConditionNameResolver::class);
        $nameResolver->method('resolve')->willReturn(new AccessConditionNames([]));

        $labeler = $this->createStub(AccessConditionLabeler::class);
        $labeler->method('reasons')->willReturn(['reason']);

        $traces ??= $this->createStub(AccessConditionTraces::class);
        $traces->method('startedHostKeys')->willReturn([]);

        return new AccessConditionGate($loader, new AccessConditionEvaluator(), $nameResolver, $labeler, $traces, $checker);
    }
}
