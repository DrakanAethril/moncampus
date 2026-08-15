<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\User;

/**
 * One HTTP request per (role, main screen), asserting what the app answers.
 *
 * This is the cheap safety net this repository was missing: with 663 routes, twelve Voters and no
 * other functional test, most regressions show up as a screen that stops rendering (500) or as one
 * that quietly starts letting the wrong role in. Both are caught here.
 *
 * The expected codes are pinned deliberately, not asserted loosely as "not a 500":
 *
 *   200 - the screen renders for that role
 *   403 - the screen exists but that role must not reach it (access_control or a Voter)
 *   302 - the screen hands over to a program-scoped URL (the role has exactly one program here)
 *
 * A 403 turning into a 200 is a security regression; a 200 turning into a 403 is a broken screen.
 * Neither is caught by asserting "< 500", which is why the table below is explicit.
 *
 * Everything runs against the empty `_test` schema plus the minimal fixture built in
 * FunctionalTestCase, so a screen that only renders because the developer's database happens to
 * hold the right data fails here.
 */
class RoleAccessSmokeTest extends FunctionalTestCase
{
    private User $student;
    private User $teacher;
    private User $admin;
    private User $tutor;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'smoke.student');
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'smoke.teacher');
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'smoke.admin');
        $this->tutor = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'smoke.tutor');

        $this->program = $this->createProgram([$this->student], [$this->teacher], $this->admin);
    }

    public function testStudentScreens(): void
    {
        $this->assertScreens($this->student, [
            '/' => 200,
            '/student-work' => 200,
            '/my/courses' => 200,
            '/my/applications' => 200,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            '/profile' => 200,
            '/about' => 200,
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            '/resources/mobile-app' => 200,
            // Both hand over to a screen scoped to the student's own program/mailbox.
            '/my/timetable' => 302,
            '/school-mail' => 302,
            // Teaching and back-office areas: a student must never get in.
            '/timetable' => 403,
            '/assignments' => 403,
            '/tools/lesson-log' => 403,
            '/tools/gradebook' => 403,
            '/tools/quiz-live' => 403,
            '/tools/job-search-tracking' => 403,
            '/tools/quiz' => 403,
            '/tools/videos' => 403,
            '/library/quiz/import/assistant' => 403,
            '/progression' => 403,
            '/library/sequences' => 403,
            '/library/sequences/assistant' => 403,
            '/help/manage' => 403,
            '/settings/configuration' => 403,
            '/settings/teaching' => 403,
            '/directory/users' => 403,
            '/ufa' => 403,
            '/ufa/configuration/contract-import' => 403,
            '/eco/parcours' => 403,
        ]);
    }

    public function testTeacherScreens(): void
    {
        $this->assertScreens($this->teacher, [
            '/' => 200,
            // The course-space index is the student's own list of programs; a teacher reaches the
            // same sequences from their program screens instead.
            '/my/courses' => 403,
            '/timetable' => 200,
            '/assignments' => 200,
            '/progression' => 200,
            '/library/sequences' => 200,
            '/library/sequences/assistant' => 200,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            '/profile' => 200,
            '/about' => 200,
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            '/resources/mobile-app' => 200,
            // Student-only screens.
            '/my/timetable' => 403,
            '/student-work' => 403,
            '/school-mail' => 403,
            '/my/applications' => 403,
            // The three class pickers of the Outils menu. They render rather than redirect here
            // because Program::$visibility defaults to StaffAdmin, which puts the fixture's own
            // class out of findAllForTeacher's reach: the picker has nothing to offer and says so
            // (toolsNoVisibleClassMessage). A 403 would be the regression - having no class to
            // work on is a setting on the class, not a permission the teacher lacks.
            '/tools/lesson-log' => 200,
            '/tools/gradebook' => 200,
            '/tools/quiz-live' => 200,
            '/tools/job-search-tracking' => 200,
            // Not a picker: the cross-class quiz list renders whatever the viewer teaches, empty
            // included, so it answers 200 rather than handing over to a class.
            '/tools/quiz' => 200,
            // Same reading: the video list shows what the viewer owns, empty included.
            '/tools/videos' => 200,
            '/library/quiz/import/assistant' => 200,
            '/help/manage' => 403,
            '/settings/configuration' => 403,
            '/settings/teaching' => 403,
            '/settings/groups' => 403,
            '/settings/groups/hierarchy' => 403,
            '/directory/users' => 403,
            '/ufa' => 403,
            '/ufa/configuration/contract-import' => 403,
            '/eco/parcours' => 403,
        ]);
    }

    public function testAdminScreens(): void
    {
        $this->assertScreens($this->admin, [
            '/' => 200,
            '/settings/configuration' => 200,
            '/settings/teaching' => 200,
            // Groups are admin-only, deliberately stricter than the rest of Settings - see
            // App\Controller\SettingsGroupsController's own note.
            '/settings/groups' => 200,
            '/settings/groups/hierarchy' => 200,
            '/directory/users' => 200,
            '/ufa' => 200,
            '/ufa/reminders' => 200,
            '/ufa/configuration/contract-import' => 200,
            '/eco/parcours' => 200,
            '/assignments' => 200,
            '/progression' => 200,
            '/library/sequences' => 200,
            '/library/sequences/assistant' => 200,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            '/profile' => 200,
            '/about' => 200,
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            // Writing the help is an admin's job, and only an admin's.
            '/help/manage' => 200,
            // Staff pick a class first, so these hand over to the program-scoped screen.
            '/tools/lesson-log' => 302,
            '/tools/gradebook' => 302,
            '/tools/quiz-live' => 302,
            '/tools/job-search-tracking' => 302,
            '/tools/quiz' => 200,
            '/tools/videos' => 200,
            '/library/quiz/import/assistant' => 200,
            // An admin is neither enrolled nor teaching, so the two personal timetables stay shut.
            '/my/timetable' => 403,
            '/timetable' => 403,
            '/student-work' => 403,
        ]);
    }

    /**
     * The screens that edit the training referential (TSF). Program-scoped, unlike the table above,
     * so they are asserted here rather than folded into it - and they are the whole point of
     * pinning a role: a teacher must not reach a program's referential settings.
     *
     * Every tab of the UFA formation area is listed, not just the one being worked on: they share
     * one shell and their content partials get edited together, which is exactly how the
     * certification zone once shipped breaking a tab no test knew about.
     */
    public function testReferentialScreens(): void
    {
        $programId = $this->program->getId();

        $screens = [
            sprintf('/programs/%d/settings/skill-groups', $programId),
            sprintf('/ufa/programs/%d', $programId),
            sprintf('/ufa/programs/%d/tutors', $programId),
            // The certification rides the denomination tab rather than one of its own.
            sprintf('/ufa/programs/%d/denomination', $programId),
            sprintf('/ufa/programs/%d/contract-modalities', $programId),
            sprintf('/ufa/programs/%d/exam-modalities', $programId),
            '/ufa/configuration/training-center',
        ];

        $this->assertScreens($this->admin, array_fill_keys($screens, 200));
        $this->assertScreens($this->teacher, array_fill_keys($screens, 403));
        $this->assertScreens($this->student, array_fill_keys($screens, 403));
    }

    public function testTutorScreens(): void
    {
        // An external apprenticeship tutor sees almost nothing: their own alternance area, and the
        // few screens open to every account. Anything else must answer 403 - see
        // project_livret_alternant_tutor_access.
        $this->assertScreens($this->tutor, [
            '/' => 302,
            '/agenda' => 200,
            '/messages' => 200,
            '/tickets' => 200,
            '/profile' => 200,
            '/about' => 200,
            // Open to every account, and empty for anyone no article is addressed to: there is
            // nothing to protect in the help, only content written for someone else.
            '/help' => 200,
            '/changelog' => 200,
            // Open to every role on purpose: the source is public and the students are its readers.
            '/technical' => 200,
            '/technical/data-model' => 200,
            '/resources/mobile-app' => 200,
            '/student-work' => 403,
            '/timetable' => 403,
            '/my/timetable' => 403,
            '/assignments' => 403,
            '/progression' => 403,
            '/library/sequences' => 403,
            '/library/sequences/assistant' => 403,
            '/tools/quiz-live' => 403,
            '/tools/job-search-tracking' => 403,
            '/tools/quiz' => 403,
            '/tools/videos' => 403,
            '/library/quiz/import/assistant' => 403,
            '/help/manage' => 403,
            '/settings/configuration' => 403,
            '/settings/teaching' => 403,
            '/directory/users' => 403,
            '/ufa' => 403,
            '/ufa/configuration/contract-import' => 403,
            '/eco/parcours' => 403,
        ]);
    }

    /**
     * @param array<string, int> $expectations path => expected status code
     */
    private function assertScreens(User $user, array $expectations): void
    {
        $this->client->loginUser($user);

        foreach ($expectations as $path => $expected) {
            $this->client->request('GET', $path);
            $actual = $this->client->getResponse()->getStatusCode();

            self::assertSame($expected, $actual, \sprintf(
                'GET %s as %s: expected %d, got %d.%s',
                $path,
                implode('/', $user->getRoles()),
                $expected,
                $actual,
                500 === $actual ? ' The screen is broken.' : '',
            ));
        }
    }
}
