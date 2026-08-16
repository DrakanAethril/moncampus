<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\WikiType;
use App\Service\WikiAccess;
use App\Service\WikiSubject;
use App\Service\WikiViewer;
use PHPUnit\Framework\TestCase;

/**
 * The wiki's single access rule, pinned row by row.
 *
 * Everything below derives from one sentence - "a wiki a student can reach, every teacher can
 * reach; a wiki with no student in it belongs to its members alone" - and the value of the test is
 * that the code does not say the sentence: it says four rows of a table, and a row quietly widening
 * is exactly the regression nobody notices. The colleagues' row is the sharp one, being the only
 * place in this application where staff are deliberately kept out.
 */
class WikiAccessTest extends TestCase
{
    private const int OWNER = 1;
    private const int OTHER_STUDENT = 2;
    private const int TEACHER = 3;
    private const int OTHER_TEACHER = 4;
    private const int STAFF = 5;
    private const int ADMIN = 6;
    private const int MEMBER = 7;

    /** @var list<string> */
    private const array STUDENT_ROLES = ['ROLE_USER', 'ROLE_STUDENT'];
    /** @var list<string> */
    private const array TEACHER_ROLES = ['ROLE_USER', 'ROLE_TEACHER'];
    /** @var list<string> */
    private const array STAFF_ROLES = ['ROLE_USER', 'ROLE_STAFF'];
    /** @var list<string> */
    private const array ADMIN_ROLES = ['ROLE_USER', 'ROLE_ADMIN'];

    // --- Personal wiki owned by a student -------------------------------------------------

    public function testStudentPersonalWikiIsEditedByItsOwnerAndBySupervision(): void
    {
        $access = new WikiAccess();
        $wiki = $this->studentPersonal();

        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::OWNER, self::STUDENT_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::ADMIN, self::ADMIN_ROLES)));

        // The whole point of "personal": no other student, ever.
        self::assertFalse($access->mayEdit($wiki, new WikiViewer(self::OTHER_STUDENT, self::STUDENT_ROLES)));
    }

    public function testStudentPersonalWikiIsManagedBySupervisionOnly(): void
    {
        $access = new WikiAccess();
        $wiki = $this->studentPersonal();

        self::assertTrue($access->mayManage($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayManage($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
        self::assertFalse($access->mayManage($wiki, new WikiViewer(self::OWNER, self::STUDENT_ROLES)));
    }

    public function testStudentPersonalWikiIsDeletedByAnAdminOnly(): void
    {
        $access = new WikiAccess();
        $wiki = $this->studentPersonal();

        self::assertTrue($access->mayDelete($wiki, new WikiViewer(self::ADMIN, self::ADMIN_ROLES)));
        self::assertFalse($access->mayDelete($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertFalse($access->mayDelete($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
        self::assertFalse($access->mayDelete($wiki, new WikiViewer(self::OWNER, self::STUDENT_ROLES)));
    }

    // --- Personal wiki owned by a teacher or a staff member -------------------------------

    public function testTeacherPersonalWikiIsTheirsAloneWithAnAdminDoor(): void
    {
        $access = new WikiAccess();
        $wiki = new WikiSubject(WikiType::Personal, self::TEACHER, false, self::TEACHER, [], false);

        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::ADMIN, self::ADMIN_ROLES)));

        // Neither a colleague nor the personnel: this is not administrative material.
        self::assertFalse($access->mayEdit($wiki, new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));
        self::assertFalse($access->mayEdit($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));

        self::assertTrue($access->mayManage($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertFalse($access->mayManage($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
        self::assertTrue($access->mayDelete($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayDelete($wiki, new WikiViewer(self::ADMIN, self::ADMIN_ROLES)));
        self::assertFalse($access->mayDelete($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
    }

    // --- Shared wiki with a student audience ----------------------------------------------

    public function testSharedStudentWikiIsReachedByEveryTeacherAndByItsAudience(): void
    {
        $access = new WikiAccess();
        $wiki = $this->sharedWithStudents();

        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::MEMBER, self::STUDENT_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        // Supervision is broad on purpose - a teacher who could not have composed this audience
        // still reads it.
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));

        // A student of an assigned class, without being a named member.
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::OTHER_STUDENT, self::STUDENT_ROLES, true)));
        // The same student once their class is no longer assigned.
        self::assertFalse($access->mayEdit($wiki, new WikiViewer(self::OTHER_STUDENT, self::STUDENT_ROLES)));
    }

    public function testSharedStudentWikiIsManagedBySupervisionAndDeletedByItsCreator(): void
    {
        $access = new WikiAccess();
        $wiki = $this->sharedWithStudents();

        self::assertTrue($access->mayManage($wiki, new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayManage($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
        self::assertFalse($access->mayManage($wiki, new WikiViewer(self::MEMBER, self::STUDENT_ROLES)));

        self::assertTrue($access->mayDelete($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayDelete($wiki, new WikiViewer(self::ADMIN, self::ADMIN_ROLES)));
        self::assertFalse($access->mayDelete($wiki, new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));
        self::assertFalse($access->mayDelete($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
    }

    // --- Shared wiki between colleagues ---------------------------------------------------

    public function testColleaguesWikiIsClosedToEveryoneButItsMembers(): void
    {
        $access = new WikiAccess();
        $wiki = $this->betweenColleagues();

        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::MEMBER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayEdit($wiki, new WikiViewer(self::ADMIN, self::ADMIN_ROLES)));

        // The line this design draws: an espace de travail between colleagues is not
        // administrative material, so staff and staff-lead stay out.
        self::assertFalse($access->mayEdit($wiki, new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));
        self::assertFalse($access->mayEdit($wiki, new WikiViewer(self::STAFF, self::STAFF_ROLES)));
        self::assertFalse($access->mayEdit($wiki, new WikiViewer(self::STAFF, ['ROLE_USER', 'ROLE_STAFF-LEAD'])));
    }

    public function testColleaguesWikiIsManagedByEveryMemberButDeletedByItsCreator(): void
    {
        $access = new WikiAccess();
        $wiki = $this->betweenColleagues();

        self::assertTrue($access->mayManage($wiki, new WikiViewer(self::MEMBER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayManage($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertFalse($access->mayManage($wiki, new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));

        self::assertTrue($access->mayDelete($wiki, new WikiViewer(self::TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayDelete($wiki, new WikiViewer(self::ADMIN, self::ADMIN_ROLES)));
        // A member manages everything else, but the trash restores pages and never the wiki.
        self::assertFalse($access->mayDelete($wiki, new WikiViewer(self::MEMBER, self::TEACHER_ROLES)));
    }

    public function testMixedWikiTipsToTheSupervisedSide(): void
    {
        $access = new WikiAccess();

        // Two colleagues plus one class: the class is what makes it a supervised wiki, and an
        // unrelated teacher walks in - the invariant doing what it says, not an exception to it.
        self::assertFalse($access->mayEdit($this->betweenColleagues(), new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));
        self::assertTrue($access->mayEdit($this->sharedWithStudents(), new WikiViewer(self::OTHER_TEACHER, self::TEACHER_ROLES)));
    }

    // --- Outside accounts -----------------------------------------------------------------

    public function testTutorsAndExternalAccountsAreExcludedEverywhere(): void
    {
        $access = new WikiAccess();
        $tutor = new WikiViewer(self::MEMBER, ['ROLE_USER', 'ROLE_TUTOR']);
        $external = new WikiViewer(self::MEMBER, ['ROLE_USER', 'ROLE_EXTERNAL']);

        foreach ([$this->studentPersonal(), $this->sharedWithStudents(), $this->betweenColleagues()] as $wiki) {
            foreach ([$tutor, $external] as $viewer) {
                self::assertFalse($access->mayEdit($wiki, $viewer));
                self::assertFalse($access->mayManage($wiki, $viewer));
                self::assertFalse($access->mayDelete($wiki, $viewer));
            }
        }
    }

    public function testAnAnonymousViewerReachesNothing(): void
    {
        $access = new WikiAccess();
        $nobody = new WikiViewer(null, []);

        self::assertFalse($access->mayEdit($this->studentPersonal(), $nobody));
        self::assertFalse($access->mayManage($this->studentPersonal(), $nobody));
        self::assertFalse($access->mayDelete($this->studentPersonal(), $nobody));
    }

    // --- The live "has a student audience" test -------------------------------------------

    public function testStudentAudienceIsAssignedClassesOrStudentMembers(): void
    {
        $access = new WikiAccess();

        self::assertFalse($access->hasStudentAudience(0, []));
        self::assertFalse($access->hasStudentAudience(0, [self::TEACHER_ROLES, self::STAFF_ROLES]));
        self::assertTrue($access->hasStudentAudience(1, [self::TEACHER_ROLES]));
        self::assertTrue($access->hasStudentAudience(0, [self::TEACHER_ROLES, self::STUDENT_ROLES]));
    }

    // --- Who may compose an audience ------------------------------------------------------

    public function testATeacherComposesOnlyFromTheClassesTheyTeach(): void
    {
        $access = new WikiAccess();

        // Reaching a wiki and composing its audience are different powers - a teacher enrols the
        // students of the programs they teach, staff enrol anyone.
        self::assertTrue($access->mayAssignProgram(self::TEACHER_ROLES, true));
        self::assertFalse($access->mayAssignProgram(self::TEACHER_ROLES, false));
        self::assertTrue($access->mayAssignProgram(self::STAFF_ROLES, false));
        self::assertTrue($access->mayAssignProgram(self::ADMIN_ROLES, false));
        self::assertFalse($access->mayAssignProgram(self::STUDENT_ROLES, true));
    }

    private function studentPersonal(): WikiSubject
    {
        return new WikiSubject(WikiType::Personal, self::OWNER, true, self::OWNER, [], false);
    }

    private function sharedWithStudents(): WikiSubject
    {
        return new WikiSubject(WikiType::Shared, null, false, self::TEACHER, [self::MEMBER], true);
    }

    private function betweenColleagues(): WikiSubject
    {
        return new WikiSubject(WikiType::Shared, null, false, self::TEACHER, [self::MEMBER], false);
    }
}
