<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Assignment;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Devoirs » (Program::$assignmentManagementEnabled) is cumulative with the `student_work`
 * feature, the way « Visibilité de l'emploi du temps » is with `timetable` next to it.
 *
 * It was applied on the writing side alone - a formation switched off refuses a new travail
 * (App\Controller\ProgramAssignmentController) and drops out of the teacher's pickers - while
 * App\Service\StudentWorkBoard read every formation the student is enrolled in. So closing the
 * box stopped nothing that had already been given: the list, the dashboard card and the mobile
 * feed all kept showing it, because all three read that one board.
 */
class StudentWorkProgramAxisTest extends FunctionalTestCase
{
    public function testAFormationThatRunsAssignmentsShowsItsWorkToItsStudents(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'work.axis.student');
        $this->assignment($this->createProgram([$student]), $student);

        $this->client->loginUser($student);
        $this->client->request('GET', '/student-work');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Lire le chapitre 3', (string) $this->client->getResponse()->getContent());
    }

    public function testSwitchingTheFormationOffAlsoStopsTheWorkAlreadyGiven(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'work.axis.student');
        $program = $this->createProgram([$student]);
        $this->assignment($program, $student);
        $program->setAssignmentManagementEnabled(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($student);
        $this->client->request('GET', '/student-work');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Lire le chapitre 3', (string) $this->client->getResponse()->getContent());
    }

    /** A published « À lire » due tomorrow - the simplest line the board can draw. */
    private function assignment(Program $program, User $author): Assignment
    {
        $assignment = new Assignment($program);
        $assignment->setTitle('Lire le chapitre 3');
        $assignment->setNature(AssignmentNature::ToRead);
        $assignment->setAudienceType(AssignmentAudienceType::Program);
        $assignment->setDueDate(new \DateTimeImmutable('tomorrow 08:00'));
        $assignment->setVisibleAt(new \DateTimeImmutable('-1 hour'));
        $assignment->setCreatedBy($author);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($assignment);
        $entityManager->flush();

        return $assignment;
    }
}
