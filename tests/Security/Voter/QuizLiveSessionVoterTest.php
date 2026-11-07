<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\QuizLiveSession;
use App\Entity\User;
use App\Security\Voter\QuizLiveSessionVoter;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Who runs a live contest and reads its results: its host; for a contest of a class, its teachers
 * and staff too. A contest of no class is its host's alone - staff included.
 */
class QuizLiveSessionVoterTest extends VoterTestCase
{
    public function testTheHostRunsTheirContestWithOrWithoutAClass(): void
    {
        $host = $this->user(['ROLE_TEACHER'], 'host');

        $this->assertGranted(new QuizLiveSessionVoter(), $host, $this->session($host, null), QuizLiveSessionVoter::MANAGE);
        $this->assertGranted(new QuizLiveSessionVoter(), $host, $this->session($host, $this->program([])), QuizLiveSessionVoter::MANAGE);
    }

    public function testTheTeachersOfItsClassAndStaffRunAClassContest(): void
    {
        $colleague = $this->user(['ROLE_TEACHER'], 'colleague');
        $program = $this->program([$colleague]);
        $session = $this->session($this->user(['ROLE_TEACHER'], 'host'), $program);

        $this->assertGranted(new QuizLiveSessionVoter(), $colleague, $session, QuizLiveSessionVoter::MANAGE);
        $this->assertGranted(new QuizLiveSessionVoter(), $this->user(['ROLE_STAFF'], 'staff'), $session, QuizLiveSessionVoter::MANAGE);
        $this->assertDenied(new QuizLiveSessionVoter(), $this->user(['ROLE_TEACHER'], 'other'), $session, QuizLiveSessionVoter::MANAGE, 'a teacher of another class');
        $this->assertDenied(new QuizLiveSessionVoter(), $this->user(['ROLE_STUDENT'], 'student'), $session, QuizLiveSessionVoter::MANAGE);
    }

    public function testAContestOfNoClassIsItsHostsAlone(): void
    {
        $session = $this->session($this->user(['ROLE_TEACHER'], 'host'), null);

        $this->assertDenied(new QuizLiveSessionVoter(), $this->user(['ROLE_ADMIN'], 'admin'), $session, QuizLiveSessionVoter::MANAGE);
        $this->assertDenied(new QuizLiveSessionVoter(), null, $session, QuizLiveSessionVoter::MANAGE);
    }

    public function testItStaysOutOfOtherSubjects(): void
    {
        $this->assertAbstains(new QuizLiveSessionVoter(), $this->user(['ROLE_TEACHER']), new \stdClass(), QuizLiveSessionVoter::MANAGE);
    }

    /** @param list<User> $teachers */
    private function program(array $teachers): Program
    {
        $program = $this->createStub(Program::class);
        $program->method('getTeachers')->willReturn(new ArrayCollection($teachers));

        return $program;
    }

    private function session(User $host, ?Program $program): QuizLiveSession
    {
        return new QuizLiveSession(new QuizInstance($program, $host), $host, 'ABC234');
    }
}
