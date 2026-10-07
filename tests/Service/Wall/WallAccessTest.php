<?php

declare(strict_types=1);

namespace App\Tests\Service\Wall;

use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallComment;
use App\Entity\WallList;
use App\Enum\WallCardStatus;
use App\Enum\WallFormat;
use App\Enum\WallRole;
use App\Repository\ProgramRepository;
use App\Service\Wall\WallAccess;
use App\Service\Wall\WallSupervisors;
use PHPUnit\Framework\TestCase;

/**
 * The handoff's « Rôles et permissions » table, one test per row - and the two things the table
 * does not say and the establishment decided: who runs a wall alongside its owner, and that nobody
 * outside a wall opens it, administrators included.
 */
class WallAccessTest extends TestCase
{
    private WallAccess $access;
    private User $teacher;
    private User $colleague;
    private User $student;
    private User $classmate;
    private Program $program;

    protected function setUp(): void
    {
        // The one question the rule asks of the database - « does this person teach a class of one
        // of these students » - answered from the class built below.
        $programs = $this->createStub(ProgramRepository::class);
        $programs->method('teachesAnyOf')->willReturnCallback(
            fn (User $teacher, array $students): bool => $this->program->getTeachers()->contains($teacher)
                && [] !== array_filter($students, fn (User $student): bool => $this->program->getStudents()->contains($student)),
        );
        $this->access = new WallAccess(new WallSupervisors($programs));
        $this->teacher = $this->user(['ROLE_TEACHER'], 'teacher');
        $this->colleague = $this->user(['ROLE_TEACHER'], 'colleague');
        $this->student = $this->user(['ROLE_STUDENT'], 'student');
        $this->classmate = $this->user(['ROLE_STUDENT'], 'classmate');

        $cohort = new Cohort('Classe', new Track('Filière', new Section('Section')));
        $today = new \DateTimeImmutable('today');
        $this->program = new Program('Formation', 'F-1', $cohort, new SchoolYear($today->modify('-1 month'), $today->modify('+6 months')));
        $this->program->addStudent($this->student);
        $this->program->addStudent($this->classmate);
        $this->program->addTeacher($this->teacher);
    }

    public function testTheOwnerDoesEverything(): void
    {
        $wall = $this->wall($this->teacher);

        self::assertSame(WallRole::Owner, $this->access->roleOf($wall, $this->teacher));
        self::assertTrue($this->access->mayManage($wall, $this->teacher));
        self::assertTrue($this->access->mayOwn($wall, $this->teacher));
        self::assertTrue($this->access->mayAddCard($wall, $this->teacher));
    }

    public function testNobodyOutsideAWallOpensItNotEvenAnAdministrator(): void
    {
        $wall = $this->wall($this->teacher);
        $card = $this->card($wall, $this->teacher);

        foreach ([$this->colleague, $this->student, $this->user(['ROLE_ADMIN'], 'admin'), $this->user(['ROLE_STAFF'], 'staff')] as $outsider) {
            self::assertNull($this->access->roleOf($wall, $outsider));
            self::assertFalse($this->access->mayView($wall, $outsider));
            self::assertFalse($this->access->maySeeCard($card, $outsider));
            self::assertFalse($this->access->mayEditCard($card, $outsider));
        }
    }

    public function testAColleagueATeacherSharesWithRunsTheWallButNeitherSharesNorDeletesIt(): void
    {
        $wall = $this->wall($this->teacher)->addMember($this->colleague);

        self::assertSame(WallRole::Manager, $this->access->roleOf($wall, $this->colleague));
        self::assertTrue($this->access->mayManage($wall, $this->colleague));
        self::assertFalse($this->access->mayOwn($wall, $this->colleague));
    }

    public function testAClassSharedWithMakesItsStudentsParticipants(): void
    {
        $wall = $this->wall($this->teacher)->addProgram($this->program);

        self::assertSame(WallRole::Participant, $this->access->roleOf($wall, $this->student));
        self::assertFalse($this->access->mayManage($wall, $this->student));
        // The class's other teachers are not on the wall: sharing with a class reaches its students.
        self::assertNull($this->access->roleOf($wall, $this->colleague));
    }

    public function testEverybodyAStudentInvitesIsAGuestTeachersIncluded(): void
    {
        $wall = $this->wall($this->student)->addMember($this->classmate)->addMember($this->teacher);

        self::assertSame(WallRole::Participant, $this->access->roleOf($wall, $this->classmate));
        self::assertSame(WallRole::Participant, $this->access->roleOf($wall, $this->teacher));
        self::assertTrue($this->access->mayManage($wall, $this->student));
        self::assertFalse($this->access->mayManage($wall, $this->teacher));
    }

    public function testTheTeachersOfAStudentsClassReadTheirWallAndWriteNothing(): void
    {
        $wall = $this->wall($this->student);
        $wall->setCommentsEnabled(true)->setParticipantsMayEditOthers(true)->setModerated(true);
        $pending = $this->card($wall, $this->student)->setStatus(WallCardStatus::Pending);

        self::assertSame(WallRole::Supervisor, $this->access->roleOf($wall, $this->teacher));
        self::assertTrue($this->access->mayView($wall, $this->teacher));
        // Everything on it, the cards awaiting validation included…
        self::assertTrue($this->access->maySeeCard($pending, $this->teacher));
        // …and no gesture at all, whatever the wall's own switches open to its participants.
        self::assertFalse($this->access->mayManage($wall, $this->teacher));
        self::assertFalse($this->access->mayOwn($wall, $this->teacher));
        self::assertFalse($this->access->mayAddCard($wall, $this->teacher));
        self::assertFalse($this->access->mayEditCard($pending, $this->teacher));
        self::assertFalse($this->access->mayComment($pending, $this->teacher));

        // A teacher of another class, the administration and a classmate who was not invited
        // are still nobody on it.
        foreach ([$this->colleague, $this->user(['ROLE_ADMIN'], 'admin'), $this->user(['ROLE_STAFF'], 'staff'), $this->classmate] as $outsider) {
            self::assertNull($this->access->roleOf($wall, $outsider));
        }
    }

    public function testAGroupsWallIsReadByTheTeachersOfAnyStudentOnIt(): void
    {
        $elsewhere = $this->user(['ROLE_STUDENT'], 'elsewhere');
        $wall = $this->wall($elsewhere)->addMember($this->student);

        // The owner is of another class; the classmate named on the wall is this teacher's.
        self::assertSame(WallRole::Supervisor, $this->access->roleOf($wall, $this->teacher));
    }

    public function testATeachersWallIsSupervisedByNobody(): void
    {
        $other = $this->user(['ROLE_TEACHER'], 'other');
        $this->program->addTeacher($other);
        $wall = $this->wall($this->teacher)->addProgram($this->program);

        // Shared with the class, read by its students - not by the class's other teachers.
        self::assertNull($this->access->roleOf($wall, $other));
    }

    public function testATeacherAStudentInvitedWritesAsAGuestAndStillReadsWhatASupervisorReads(): void
    {
        $wall = $this->wall($this->student)->addMember($this->teacher)->addMember($this->classmate);
        $wall->setModerated(true);
        $pending = $this->card($wall, $this->classmate)->setStatus(WallCardStatus::Pending);

        self::assertSame(WallRole::Participant, $this->access->roleOf($wall, $this->teacher));
        self::assertTrue($this->access->mayAddCard($wall, $this->teacher));
        self::assertTrue($this->access->maySeeCard($pending, $this->teacher));
        self::assertFalse($this->access->mayEditCard($pending, $this->teacher));
    }

    public function testATutorHasNoWallEvenWhenNamedOnOne(): void
    {
        $tutor = $this->user(['ROLE_TUTOR'], 'tutor');
        $wall = $this->wall($this->teacher)->addMember($tutor);

        self::assertNull($this->access->roleOf($wall, $tutor));
    }

    public function testAParticipantAddsACardOnlyWhileTheWallLetsThem(): void
    {
        $wall = $this->wall($this->teacher)->addProgram($this->program);
        self::assertTrue($this->access->mayAddCard($wall, $this->student));

        $wall->setParticipantsMayAdd(false);
        self::assertFalse($this->access->mayAddCard($wall, $this->student));
        self::assertTrue($this->access->mayAddCard($wall, $this->teacher));
    }

    public function testAParticipantChangesTheirOwnCardsAndTheOthersOnlyWhenTheWallSaysSo(): void
    {
        $wall = $this->wall($this->teacher)->addProgram($this->program);
        $own = $this->card($wall, $this->student);
        $others = $this->card($wall, $this->classmate);

        self::assertTrue($this->access->mayEditCard($own, $this->student));
        self::assertFalse($this->access->mayEditCard($others, $this->student));
        self::assertTrue($this->access->mayEditCard($others, $this->teacher));

        $wall->setParticipantsMayEditOthers(true);
        self::assertTrue($this->access->mayEditCard($others, $this->student));
    }

    public function testModerationHoldsAParticipantsCardAndNeverAManagers(): void
    {
        $wall = $this->wall($this->teacher)->addProgram($this->program)->addMember($this->colleague);
        self::assertSame(WallCardStatus::Published, $this->access->statusOfNewCard($wall, $this->student));

        $wall->setModerated(true);
        self::assertSame(WallCardStatus::Pending, $this->access->statusOfNewCard($wall, $this->student));
        self::assertSame(WallCardStatus::Published, $this->access->statusOfNewCard($wall, $this->teacher));
        self::assertSame(WallCardStatus::Published, $this->access->statusOfNewCard($wall, $this->colleague));
    }

    public function testACardAwaitingValidationIsReadByItsAuthorAndTheManagersOnly(): void
    {
        $wall = $this->wall($this->teacher)->addProgram($this->program)->addMember($this->colleague);
        $wall->setParticipantsMayEditOthers(true);
        $card = $this->card($wall, $this->student)->setStatus(WallCardStatus::Pending);

        self::assertTrue($this->access->maySeeCard($card, $this->student));
        self::assertTrue($this->access->maySeeCard($card, $this->teacher));
        self::assertTrue($this->access->maySeeCard($card, $this->colleague));
        self::assertFalse($this->access->maySeeCard($card, $this->classmate));
        // « Modification des cartes des autres » opens what a participant can read, nothing more.
        self::assertFalse($this->access->mayEditCard($card, $this->classmate));
        self::assertTrue($this->access->mayEditCard($card, $this->student));
    }

    public function testCommentsExistOnlyWhileTheSwitchIsOn(): void
    {
        $wall = $this->wall($this->teacher)->addProgram($this->program);
        $card = $this->card($wall, $this->teacher);
        self::assertFalse($this->access->mayComment($card, $this->student));
        self::assertFalse($this->access->mayComment($card, $this->teacher));

        $wall->setCommentsEnabled(true);
        self::assertTrue($this->access->mayComment($card, $this->student));

        $comment = new WallComment($card, $this->student, 'Bonjour');
        self::assertTrue($this->access->mayDeleteComment($comment, $this->student));
        self::assertTrue($this->access->mayDeleteComment($comment, $this->teacher));
        self::assertFalse($this->access->mayDeleteComment($comment, $this->classmate));

        $wall->setCommentsEnabled(false);
        self::assertFalse($this->access->mayDeleteComment($comment, $this->student));
    }

    /** @param list<string> $roles */
    private function user(array $roles, string $username): User
    {
        $user = new User($username);
        $user->setRoles($roles);

        return $user;
    }

    private function wall(User $owner): Wall
    {
        $wall = new Wall($owner, 'Mur de test', WallFormat::Columns);
        new WallList($wall, 'À faire');

        return $wall;
    }

    private function card(Wall $wall, User $author): WallCard
    {
        $list = $wall->getLists()->first();
        \assert($list instanceof WallList);

        return new WallCard($list, 'Carte', $author);
    }
}
