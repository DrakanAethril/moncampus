<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Assignment;
use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a travail is soft, and stamped once.
 *
 * The « once » is the rule worth pinning: two screens offer the gesture (the « Travaux » list and
 * the cahier de texte), a double click on either posts twice, and a second stamp would rewrite who
 * deleted the travail and when - reading back « supprimé par » would then name whoever clicked
 * last rather than whoever decided.
 */
class AssignmentSoftDeletionTest extends TestCase
{
    public function testANewAssignmentIsNotDeleted(): void
    {
        $assignment = $this->assignment();

        $this->assertFalse($assignment->isDeleted());
        $this->assertNull($assignment->getDeletedAt());
        $this->assertNull($assignment->getDeletedBy());
    }

    public function testDeletingStampsWhoAndWhen(): void
    {
        $assignment = $this->assignment();
        $teacher = new User('p.martin');

        $assignment->delete($teacher);

        $this->assertTrue($assignment->isDeleted());
        $this->assertSame($teacher, $assignment->getDeletedBy());
        $this->assertNotNull($assignment->getDeletedAt());
    }

    public function testASecondDeletionChangesNothing(): void
    {
        $assignment = $this->assignment();
        $first = new User('p.martin');

        $assignment->delete($first);
        $at = $assignment->getDeletedAt();

        $assignment->delete(new User('a.dupont'));

        $this->assertSame($first, $assignment->getDeletedBy());
        $this->assertSame($at, $assignment->getDeletedAt());
    }

    private function assignment(): Assignment
    {
        $program = new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            new Cohort('SIO-2', new Track('SIO', new Section('BTS'))),
            new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30')),
        );

        return (new Assignment($program))->setTitle('TP 3 - réseaux');
    }
}
