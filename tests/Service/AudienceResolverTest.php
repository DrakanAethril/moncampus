<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AgendaEvent;
use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Service\AudienceResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the visibility rule App\Security\Voter\AudienceTargetableVoter delegates to for every
 * AgendaEvent, Announcement and MessageThread.
 *
 * Covers all five audience types rather than just the Program one, because isVisibleTo() answers
 * "is this user in the audience" by a different route than resolveRecipients() answers "who is the
 * audience": the first only needs a yes/no and must not materialise every student and teacher of
 * every targeted Program to produce it. Two routes to one truth is a shape that drifts, so
 * testConsistencyBetweenTheTwoEntryPoints() asserts they agree across a matrix of cases - that
 * property, not either implementation, is the contract.
 *
 * Deliberately no database. The doubles below re-implement the repositories' real predicates
 * (UserRepository::findActiveMatchingRoles() and friends filter `inactiveDate IS NULL` in DQL then
 * match roles in PHP) over an in-memory roster, so a test can't quietly assert something the
 * production query would not.
 */
class AudienceResolverTest extends TestCase
{
    /** @var list<User> */
    private array $roster = [];

    private function resolver(): AudienceResolver
    {
        return new AudienceResolver($this->userRepository(), $this->programRepository());
    }

    /**
     * Mirrors UserRepository's real behaviour: only non-inactive users are ever candidates, then
     * findActiveMatchingRoles() keeps those holding ALL the required roles and
     * findActiveMatchingAnyRole() those holding at least one.
     */
    private function userRepository(): UserRepository
    {
        $active = fn (): array => array_values(array_filter($this->roster, static fn (User $u): bool => null === $u->getInactiveDate()));

        $repository = $this->createStub(UserRepository::class);
        $repository->method('findActiveMatchingRoles')->willReturnCallback(
            static fn (array $required, array $excluded = []) => array_values(array_filter(
                $active(),
                static fn (User $u): bool => [] === array_diff($required, $u->getRoles()) && !\in_array($u->getId(), $excluded, true),
            )),
        );
        $repository->method('findActiveMatchingAnyRole')->willReturnCallback(
            static fn (array $anyOf, array $excluded = []) => array_values(array_filter(
                $active(),
                static fn (User $u): bool => [] !== array_intersect($anyOf, $u->getRoles()) && !\in_array($u->getId(), $excluded, true),
            )),
        );

        return $repository;
    }

    /**
     * Derives the membership ids from the very Program objects the test wired, rather than from a
     * hardcoded list - the double therefore cannot disagree with what resolveRecipients() reads off
     * those same collections, which is what makes the consistency property below meaningful.
     */
    private function programRepository(): ProgramRepository
    {
        $idsWhere = fn (User $user, string $getter): array => array_values(array_map(
            static fn (Program $p): int => (int) $p->getId(),
            array_filter($this->knownPrograms, static fn (Program $p): bool => $p->{$getter}()->contains($user)),
        ));

        $repository = $this->createStub(ProgramRepository::class);
        $repository->method('findIdsWithUserAsStudent')->willReturnCallback(fn (User $u): array => $idsWhere($u, 'getStudents'));
        $repository->method('findIdsWithUserAsTeacher')->willReturnCallback(fn (User $u): array => $idsWhere($u, 'getTeachers'));

        return $repository;
    }

    /** @var list<Program> */
    private array $knownPrograms = [];

    private function user(int $id, array $roles = [], bool $inactive = false): User
    {
        $user = new User('user-'.$id);
        // Ids have to be real: the audience arithmetic deduplicates by id and Doctrine normally
        // assigns them on flush, of which there is none here.
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setRoles($roles);

        if ($inactive) {
            $user->setInactiveDate(new \DateTimeImmutable('-1 day'));
        }

        $this->roster[] = $user;

        return $user;
    }

    private function program(int $id): Program
    {
        // Cohort/SchoolYear are constructor requirements this rule never reads - stubbing them
        // keeps the test about audience arithmetic rather than about building a structure tree.
        $program = new Program('Programme '.$id, 'P'.$id, $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        (new \ReflectionProperty(Program::class, 'id'))->setValue($program, $id);
        $this->knownPrograms[] = $program;

        return $program;
    }

    private function event(MessageAudienceType $type): AgendaEvent
    {
        $event = new AgendaEvent();
        $event->setAudienceType($type);

        return $event;
    }

    private function programEvent(Program $program, bool $students, bool $teachers): AgendaEvent
    {
        $event = $this->event(MessageAudienceType::Program);
        $event->addProgram($program);
        $event->setIncludeStudents($students);
        $event->setIncludeTeachers($teachers);

        return $event;
    }

    // ---- Program audience --------------------------------------------------------------------

    public function testStudentOfATargetedProgramIsReached(): void
    {
        $student = $this->user(1);
        $program = $this->program(10);
        $program->addStudent($student);

        $event = $this->programEvent($program, students: true, teachers: false);

        self::assertTrue($this->resolver()->isVisibleTo($event, $student));
        self::assertSame([$student], $this->resolver()->resolveRecipients($event));
    }

    public function testTeacherIsExcludedWhenOnlyStudentsAreIncluded(): void
    {
        $teacher = $this->user(2);
        $program = $this->program(10);
        $program->addTeacher($teacher);

        self::assertFalse($this->resolver()->isVisibleTo($this->programEvent($program, students: true, teachers: false), $teacher));
        self::assertTrue($this->resolver()->isVisibleTo($this->programEvent($program, students: false, teachers: true), $teacher));
    }

    public function testSomeoneOutsideEveryTargetedProgramIsNotReached(): void
    {
        $insider = $this->user(1);
        $outsider = $this->user(99);

        $targeted = $this->program(10);
        $targeted->addStudent($insider);

        $untargeted = $this->program(20);
        $untargeted->addStudent($outsider);

        self::assertFalse($this->resolver()->isVisibleTo($this->programEvent($targeted, students: true, teachers: false), $outsider));
    }

    /**
     * The union across several Programs, deduplicated - and the case a fetch-join that dropped one
     * Program from the collection would break silently.
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

        $event = $this->programEvent($first, students: true, teachers: false);
        $event->addProgram($second);

        self::assertCount(3, $this->resolver()->resolveRecipients($event), 'the user in both Programs must appear once');
        foreach ([$onlyInFirst, $inBoth, $onlyInSecond] as $expected) {
            self::assertTrue($this->resolver()->isVisibleTo($event, $expected));
        }
    }

    public function testAnEventWithoutProgramsReachesNobody(): void
    {
        $event = $this->event(MessageAudienceType::Program);
        $event->setIncludeStudents(true);
        $event->setIncludeTeachers(true);

        self::assertSame([], $this->resolver()->resolveRecipients($event));
        self::assertFalse($this->resolver()->isVisibleTo($event, $this->user(1)));
    }

    // ---- Role-wide audiences -----------------------------------------------------------------

    public function testAllStudentsReachesActiveStudentsOnly(): void
    {
        $student = $this->user(1, ['ROLE_STUDENT']);
        $teacher = $this->user(2, ['ROLE_TEACHER']);
        $formerStudent = $this->user(3, ['ROLE_STUDENT'], inactive: true);

        $event = $this->event(MessageAudienceType::AllStudents);
        $resolver = $this->resolver();

        self::assertTrue($resolver->isVisibleTo($event, $student));
        self::assertFalse($resolver->isVisibleTo($event, $teacher));
        self::assertFalse($resolver->isVisibleTo($event, $formerStudent), 'an inactivated account is not part of any audience');
    }

    public function testAllTeachersReachesActiveTeachersOnly(): void
    {
        $teacher = $this->user(1, ['ROLE_TEACHER']);
        $student = $this->user(2, ['ROLE_STUDENT']);

        $event = $this->event(MessageAudienceType::AllTeachers);
        $resolver = $this->resolver();

        self::assertTrue($resolver->isVisibleTo($event, $teacher));
        self::assertFalse($resolver->isVisibleTo($event, $student));
    }

    /** AllStaff matches ANY of admin/staff/staff-lead, unlike the two above which require the role. */
    public function testAllStaffReachesAnyOfTheThreeStaffRoles(): void
    {
        $admin = $this->user(1, ['ROLE_ADMIN']);
        $staff = $this->user(2, ['ROLE_STAFF']);
        $lead = $this->user(3, ['ROLE_STAFF-LEAD']);
        $student = $this->user(4, ['ROLE_STUDENT']);

        $event = $this->event(MessageAudienceType::AllStaff);
        $resolver = $this->resolver();

        foreach ([$admin, $staff, $lead] as $insider) {
            self::assertTrue($resolver->isVisibleTo($event, $insider));
        }
        self::assertFalse($resolver->isVisibleTo($event, $student));
    }

    /**
     * ROLE_TUTOR and ROLE_EXTERNAL are outside accounts and match none of the role-wide audiences -
     * the property the "excluded from message recipients" rule rests on.
     */
    public function testOutsideAccountsMatchNoRoleWideAudience(): void
    {
        $tutor = $this->user(1, ['ROLE_TUTOR']);
        $external = $this->user(2, ['ROLE_EXTERNAL']);
        $resolver = $this->resolver();

        foreach ([MessageAudienceType::AllStudents, MessageAudienceType::AllTeachers, MessageAudienceType::AllStaff] as $type) {
            self::assertFalse($resolver->isVisibleTo($this->event($type), $tutor));
            self::assertFalse($resolver->isVisibleTo($this->event($type), $external));
        }
    }

    // ---- Manual and unset --------------------------------------------------------------------

    public function testManualAudienceReachesExactlyTheListedUsers(): void
    {
        $listed = $this->user(1, ['ROLE_STUDENT']);
        $unlisted = $this->user(2, ['ROLE_STUDENT']);

        $event = $this->event(MessageAudienceType::Manual);
        $event->addManualRecipient($listed);

        $resolver = $this->resolver();
        self::assertTrue($resolver->isVisibleTo($event, $listed));
        self::assertFalse($resolver->isVisibleTo($event, $unlisted));
    }

    public function testAnEventWithNoAudienceTypeReachesNobody(): void
    {
        $event = new AgendaEvent();
        $user = $this->user(1, ['ROLE_STUDENT']);

        self::assertSame([], $this->resolver()->resolveRecipients($event));
        self::assertFalse($this->resolver()->isVisibleTo($event, $user));
    }

    // ---- The property that ties the two entry points together --------------------------------

    /**
     * isVisibleTo() must always agree with membership of resolveRecipients(). This is the guard
     * that lets the former stop materialising the latter: any future divergence between the
     * yes/no path and the list path fails here rather than silently hiding somebody's event.
     */
    public function testConsistencyBetweenTheTwoEntryPoints(): void
    {
        $student = $this->user(1, ['ROLE_STUDENT']);
        $teacher = $this->user(2, ['ROLE_TEACHER']);
        $staff = $this->user(3, ['ROLE_STAFF']);
        $inactive = $this->user(4, ['ROLE_STUDENT'], inactive: true);
        $tutor = $this->user(5, ['ROLE_TUTOR']);

        $program = $this->program(10);
        $program->addStudent($student);
        $program->addTeacher($teacher);

        $manual = $this->event(MessageAudienceType::Manual);
        $manual->addManualRecipient($teacher);

        $targets = [
            'program/students' => $this->programEvent($program, students: true, teachers: false),
            'program/teachers' => $this->programEvent($program, students: false, teachers: true),
            'program/both' => $this->programEvent($program, students: true, teachers: true),
            'program/neither' => $this->programEvent($program, students: false, teachers: false),
            'allStudents' => $this->event(MessageAudienceType::AllStudents),
            'allTeachers' => $this->event(MessageAudienceType::AllTeachers),
            'allStaff' => $this->event(MessageAudienceType::AllStaff),
            'manual' => $manual,
            'unset' => new AgendaEvent(),
        ];

        $resolver = $this->resolver();
        foreach ($targets as $label => $target) {
            $recipients = $resolver->resolveRecipients($target);

            foreach ([$student, $teacher, $staff, $inactive, $tutor] as $candidate) {
                self::assertSame(
                    \in_array($candidate, $recipients, true),
                    $resolver->isVisibleTo($target, $candidate),
                    \sprintf('%s disagrees for %s', $label, $candidate->getUsername()),
                );
            }
        }
    }
}
