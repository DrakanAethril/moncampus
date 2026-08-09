<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AgendaEvent;
use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\UserRepository;
use App\Service\AudienceResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Program-audience visibility rule, which App\Security\Voter\AudienceTargetableVoter
 * delegates to for every AgendaEvent, Announcement and MessageThread.
 *
 * Written ahead of the fetch-join added to AgendaEventRepository::findUpcoming() for the home
 * dashboard's N+1: fetch-joining a to-many association is exactly the change that can silently
 * truncate the collection it hydrates (an inner join drops the rows with none, a join carrying a
 * WHERE keeps only the matching entries), and this resolver reads `getPrograms()` and each
 * Program's students/teachers directly. A truncated collection does not error - it quietly makes
 * an event invisible to people who should see it, which no existing test would have caught.
 *
 * Deliberately no database: the rule under test is set arithmetic over three collections, so real
 * entities wired by hand say more per line than a fixture would, and the test stays in the unit
 * suite where it runs in milliseconds.
 */
class AudienceResolverTest extends TestCase
{
    private function resolver(): AudienceResolver
    {
        // Only the Program branch is exercised here, and it never touches the repository - the
        // AllStudents/AllTeachers/AllStaff branches are the ones that delegate to it.
        return new AudienceResolver($this->createStub(UserRepository::class));
    }

    private function user(int $id): User
    {
        $user = new User('user-'.$id);
        // resolveProgramAudience() deduplicates by id, so the ids have to be real - Doctrine
        // normally assigns them on flush and there is no database here.
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function program(int $id): Program
    {
        // Cohort/SchoolYear are constructor requirements of Program that this rule never reads -
        // stubs keep the test about the audience arithmetic rather than about building a structure.
        $program = new Program('Programme '.$id, 'P'.$id, $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        (new \ReflectionProperty(Program::class, 'id'))->setValue($program, $id);

        return $program;
    }

    private function event(Program $program, bool $students, bool $teachers): AgendaEvent
    {
        $event = new AgendaEvent();
        $event->setAudienceType(MessageAudienceType::Program);
        $event->addProgram($program);
        $event->setIncludeStudents($students);
        $event->setIncludeTeachers($teachers);

        return $event;
    }

    public function testStudentOfATargetedProgramIsReached(): void
    {
        $student = $this->user(1);
        $program = $this->program(10);
        $program->addStudent($student);

        $event = $this->event($program, students: true, teachers: false);

        self::assertTrue($this->resolver()->isVisibleTo($event, $student));
        self::assertSame([$student], $this->resolver()->resolveRecipients($event));
    }

    public function testTeacherIsExcludedWhenOnlyStudentsAreIncluded(): void
    {
        $teacher = $this->user(2);
        $program = $this->program(10);
        $program->addTeacher($teacher);

        $event = $this->event($program, students: true, teachers: false);

        self::assertFalse($this->resolver()->isVisibleTo($event, $teacher));
    }

    public function testSomeoneOutsideEveryTargetedProgramIsNotReached(): void
    {
        $insider = $this->user(1);
        $outsider = $this->user(99);

        $targeted = $this->program(10);
        $targeted->addStudent($insider);

        $event = $this->event($targeted, students: true, teachers: false);

        self::assertFalse($this->resolver()->isVisibleTo($event, $outsider));
    }

    /**
     * The union across several Programs, deduplicated - this is what a fetch-join that dropped the
     * second Program from the collection would break, and it would break it silently.
     */
    public function testAudienceIsTheDeduplicatedUnionOverEveryTargetedProgram(): void
    {
        $onlyInFirst = $this->user(1);
        $inBoth = $this->user(2);
        $onlyInSecond = $this->user(3);

        $first = $this->program(10);
        $first->addStudent($onlyInFirst);
        $first->addStudent($inBoth);

        $second = $this->program(20);
        $second->addStudent($inBoth);
        $second->addStudent($onlyInSecond);

        $event = $this->event($first, students: true, teachers: false);
        $event->addProgram($second);

        $recipients = $this->resolver()->resolveRecipients($event);

        self::assertCount(3, $recipients, 'the user in both Programs must appear once, not twice');
        foreach ([$onlyInFirst, $inBoth, $onlyInSecond] as $expected) {
            self::assertTrue($this->resolver()->isVisibleTo($event, $expected));
        }
    }

    /**
     * An event targeting no Program at all reaches nobody. Worth pinning next to the others: this
     * is the shape a truncating join produces, so a change that broke the collection would show up
     * as "everything looks like this case" rather than as an error.
     */
    public function testAnEventWithoutProgramsReachesNobody(): void
    {
        $event = new AgendaEvent();
        $event->setAudienceType(MessageAudienceType::Program);
        $event->setIncludeStudents(true);
        $event->setIncludeTeachers(true);

        self::assertSame([], $this->resolver()->resolveRecipients($event));
        self::assertFalse($this->resolver()->isVisibleTo($event, $this->user(1)));
    }
}
