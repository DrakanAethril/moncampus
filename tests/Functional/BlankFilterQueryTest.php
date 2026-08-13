<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\User;

/**
 * The screens whose toolbar is a plain GET form with a blank "Toutes/Tous/—" option, requested the
 * way that toolbar actually submits: every filter present and empty.
 *
 * These are not hypothetical URLs. Those forms auto-submit on change, so the first teacher to type
 * in the search box while the other filters sat on their default sent `?q=x&classe=&type=&etat=` -
 * and InputBag::getInt() answers a BadRequestException to the empty string, which reached
 * production as `Input value "classe" cannot be converted to "int"` on GET /assignments.
 *
 * RoleAccessSmokeTest requests these same paths bare, which is why it never saw it: the bug lives
 * entirely in the query string. So the value of this file is the query strings, not the paths -
 * when a screen gains a filter whose "all" option is blank, add its URL here.
 */
class BlankFilterQueryTest extends FunctionalTestCase
{
    private User $teacher;
    private User $admin;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_CAMPUS'], 'blank.teacher');
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'blank.admin');
        $this->program = $this->createProgram([], [$this->teacher], $this->admin);
    }

    public function testTeacherFilterBarsRenderWhenEveryFilterIsBlank(): void
    {
        $this->assertRenders($this->teacher, [
            // The production error itself.
            '/assignments?q=&classe=&type=&etat=',
            '/tools/quiz?program=',
            // Already guarded before this fix; pinned so the guard is not lost.
            '/library/sequences?q=&niveau=&option=&bloc=',
        ]);
    }

    public function testStaffFilterBarsRenderWhenEveryFilterIsBlank(): void
    {
        $this->assertRenders($this->admin, [
            '/ufa/reminders?period=',
            \sprintf('/programs/%d/internship/tutors/reminders?period=', $this->program->getId()),
        ]);
    }

    /**
     * A hand-edited URL is the same boundary: it must show the unfiltered screen rather than an
     * error page, because there is nothing here worth defending with a 400.
     */
    public function testRubbishInAFilterIsIgnoredRatherThanRejected(): void
    {
        $this->assertRenders($this->teacher, [
            '/assignments?classe=toutes',
            '/assignments?classe[]=1',
            '/assignments?etat=n-importe-quoi',
            '/tools/quiz?program=x',
        ]);
    }

    /** @param list<string> $urls */
    private function assertRenders(User $user, array $urls): void
    {
        $this->client->loginUser($user);

        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            $status = $this->client->getResponse()->getStatusCode();

            self::assertSame(200, $status, \sprintf('GET %s answered %d; a blank filter must render the unfiltered screen.', $url, $status));
        }
    }
}
