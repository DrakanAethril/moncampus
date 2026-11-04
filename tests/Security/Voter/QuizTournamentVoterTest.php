<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\User;
use App\Enum\QuizTournamentPalette;
use App\Security\Voter\QuizTournamentVoter;
use App\Service\QuizTournament\QuizTournamentAccess;
use App\Service\QuizTournament\QuizTournamentClass;
use Symfony\Component\Clock\MockClock;

/**
 * The routing of QuizTournamentVoter (design/validated/quiz-tournament.md §2): the decisions are
 * pinned by QuizTournamentEligibilityTest, so this pins that each attribute reaches the right one
 * with the right facts - and that a closed tournament stays the administrators'.
 */
class QuizTournamentVoterTest extends VoterTestCase
{
    private const string NOW = '2026-12-01 10:00';

    public function testOnlyAnAdministratorCreatesAndManages(): void
    {
        $tournament = $this->tournament();

        $this->assertGranted($this->voter(), $this->user(['ROLE_ADMIN']), null, QuizTournamentVoter::CREATE);
        $this->assertGranted($this->voter(), $this->user(['ROLE_ADMIN']), $tournament, QuizTournamentVoter::MANAGE);

        foreach ([['ROLE_STAFF'], ['ROLE_STAFF-LEAD'], ['ROLE_TEACHER'], ['ROLE_STUDENT']] as $roles) {
            $this->assertDenied($this->voter(), $this->user($roles), null, QuizTournamentVoter::CREATE);
            $this->assertDenied($this->voter(), $this->user($roles), $tournament, QuizTournamentVoter::MANAGE);
        }
    }

    public function testAClosedTournamentIsSeenByTheAdministratorsAlone(): void
    {
        $tournament = $this->tournament(opened: false);

        $this->assertGranted($this->voter(), $this->user(['ROLE_ADMIN']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertDenied($this->voter(reached: true), $this->user(['ROLE_STUDENT']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertDenied($this->voter(teaches: true), $this->user(['ROLE_TEACHER']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertDenied($this->voter(), $this->user(['ROLE_STAFF']), $tournament, QuizTournamentVoter::VIEW);
    }

    public function testAnOpenedTournamentIsSeenByItsStudentsItsTeachersAndTheStaff(): void
    {
        $tournament = $this->tournament();

        $this->assertGranted($this->voter(reached: true), $this->user(['ROLE_STUDENT']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertDenied($this->voter(reached: false), $this->user(['ROLE_STUDENT']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertGranted($this->voter(teaches: true), $this->user(['ROLE_TEACHER']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertDenied($this->voter(teaches: false), $this->user(['ROLE_TEACHER']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertGranted($this->voter(), $this->user(['ROLE_STAFF']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertDenied($this->voter(lit: false), $this->user(['ROLE_STAFF']), $tournament, QuizTournamentVoter::VIEW);
        $this->assertDenied($this->voter(reached: true, teaches: true), $this->user(['ROLE_TUTOR']), $tournament, QuizTournamentVoter::VIEW);
    }

    public function testPlayAsksTheAccessFacts(): void
    {
        $tournament = $this->tournament();
        $student = $this->user(['ROLE_STUDENT']);

        $this->assertGranted($this->voter(canPlay: true), $student, $tournament, QuizTournamentVoter::PLAY);
        $this->assertDenied($this->voter(canPlay: false), $student, $tournament, QuizTournamentVoter::PLAY);
    }

    public function testARoundIsOpenedByATeacherOfThatClassWhileTheTournamentRuns(): void
    {
        $running = $this->tournament();
        $class = new QuizTournamentClass($running, $this->createStub(Program::class));
        $teacher = $this->user(['ROLE_TEACHER']);

        $this->assertGranted($this->voter(teaches: true, teachesThatClass: true), $teacher, $running, QuizTournamentVoter::OPEN_ROUND);
        $this->assertGranted($this->voter(teaches: true, teachesThatClass: true), $teacher, $class, QuizTournamentVoter::OPEN_ROUND);
        $this->assertDenied($this->voter(teaches: true, teachesThatClass: false), $teacher, $class, QuizTournamentVoter::OPEN_ROUND, 'another of the targeted classes');
        $this->assertDenied($this->voter(teaches: false), $teacher, $running, QuizTournamentVoter::OPEN_ROUND);

        $notStarted = $this->tournament(startsAt: '2027-01-01');
        $this->assertDenied($this->voter(teaches: true, teachesThatClass: true), $teacher, $notStarted, QuizTournamentVoter::OPEN_ROUND);

        // An administrator who does not teach the class has no round to open.
        $this->assertDenied($this->voter(), $this->user(['ROLE_ADMIN']), $class, QuizTournamentVoter::OPEN_ROUND);
    }

    public function testItStaysOutOfOtherSubjects(): void
    {
        $this->assertAbstains($this->voter(), $this->user(['ROLE_ADMIN']), new \stdClass(), QuizTournamentVoter::VIEW);
        $this->assertAbstains($this->voter(), $this->user(['ROLE_ADMIN']), null, QuizTournamentVoter::MANAGE);
        $this->assertAbstains($this->voter(), $this->user(['ROLE_ADMIN']), new \stdClass(), QuizTournamentVoter::OPEN_ROUND);
        $this->assertAbstains($this->voter(), $this->user(['ROLE_ADMIN']), $this->tournament(), 'SOMETHING_ELSE');
    }

    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $this->assertDenied($this->voter(), null, $this->tournament(), QuizTournamentVoter::VIEW);
    }

    private function voter(bool $lit = true, bool $reached = false, bool $teaches = false, bool $teachesThatClass = false, bool $canPlay = false): QuizTournamentVoter
    {
        $access = $this->createStub(QuizTournamentAccess::class);
        $access->method('isFeatureLit')->willReturn($lit);
        $access->method('isReached')->willReturn($reached);
        $access->method('teachesATargetedProgram')->willReturn($teaches);
        $access->method('teachesTargetedProgram')->willReturn($teachesThatClass);
        $access->method('canPlay')->willReturn($canPlay);

        return new QuizTournamentVoter($access, new MockClock(self::NOW));
    }

    private function tournament(bool $opened = true, string $startsAt = '2026-11-10'): QuizTournament
    {
        $tournament = new QuizTournament('Tournoi', new \DateTimeImmutable($startsAt), new \DateTimeImmutable('2027-02-10'), QuizTournamentPalette::Lavender, new User('creator'));
        if ($opened) {
            $tournament->open(new User('admin'), new \DateTimeImmutable('2026-11-01'));
        }

        return $tournament;
    }
}
