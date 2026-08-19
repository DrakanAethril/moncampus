<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cohort;
use App\Entity\GroupBatch;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Sharing a saved lot of groups with colleagues who teach the same class.
 *
 * Only the collection rules are pinned here - who may reach the endpoint is
 * ProgramToolsController's job, and the recipients themselves are re-read from the Program there.
 * What the entity owes is that the list stays a set, that the owner never lands in their own
 * recipients (the lot already sits in their "Mes groupes", and a duplicate row would show it twice
 * on their own screen), and that sharing with nobody is an ordinary state rather than an error.
 */
class GroupBatchSharingTest extends TestCase
{
    public function testANewLotIsSharedWithNobody(): void
    {
        $batch = $this->batch();

        self::assertCount(0, $batch->getSharedTeachers());
    }

    public function testATeacherIsAddedOnceHoweverOftenTheyAreSubmitted(): void
    {
        $batch = $this->batch();
        $colleague = new User('colleague');

        $batch->addSharedTeacher($colleague);
        $batch->addSharedTeacher($colleague);

        self::assertCount(1, $batch->getSharedTeachers());
        self::assertTrue($batch->isSharedWith($colleague));
    }

    public function testTheOwnerIsNeverOneOfTheirOwnRecipients(): void
    {
        $owner = new User('owner');
        $batch = $this->batch($owner);

        $batch->addSharedTeacher($owner);

        self::assertCount(0, $batch->getSharedTeachers());
        self::assertFalse($batch->isSharedWith($owner));
    }

    public function testRemovingTheLastRecipientLeavesTheLotUnshared(): void
    {
        $batch = $this->batch();
        $colleague = new User('colleague');
        $batch->addSharedTeacher($colleague);

        $batch->removeSharedTeacher($colleague);

        self::assertCount(0, $batch->getSharedTeachers());
        self::assertFalse($batch->isSharedWith($colleague));
    }

    public function testAStrangerReadsNothing(): void
    {
        $batch = $this->batch();
        $batch->addSharedTeacher(new User('colleague'));

        self::assertFalse($batch->isSharedWith(new User('stranger')));
    }

    private function batch(?User $owner = null): GroupBatch
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));

        return new GroupBatch($program, $owner ?? new User('owner'), 'TP réseau', [[1, 2], [3, 4]]);
    }
}
