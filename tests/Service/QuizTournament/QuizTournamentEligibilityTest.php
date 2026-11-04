<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Service\QuizTournament\QuizTournamentEligibility;
use PHPUnit\Framework\TestCase;

/**
 * Who takes part in a tournament, who reads it and who opens a round in it
 * (design/validated/quiz-tournament.md §2), on primitives.
 */
class QuizTournamentEligibilityTest extends TestCase
{
    private const array STUDENT = ['ROLE_USER', 'ROLE_STUDENT'];
    private const array TEACHER = ['ROLE_USER', 'ROLE_TEACHER'];
    private const array STAFF = ['ROLE_USER', 'ROLE_STAFF'];
    private const array STAFF_LEAD = ['ROLE_USER', 'ROLE_STAFF-LEAD'];
    private const array ADMIN = ['ROLE_USER', 'ROLE_ADMIN'];
    private const array TUTOR = ['ROLE_USER', 'ROLE_TUTOR'];

    public function testAStudentReachedByTheTargetingIsEligible(): void
    {
        self::assertTrue(QuizTournamentEligibility::isEligible(self::STUDENT, true));
        self::assertFalse(QuizTournamentEligibility::isEligible(self::STUDENT, false));
    }

    public function testOnlyAStudentIsEligible(): void
    {
        self::assertFalse(QuizTournamentEligibility::isEligible(self::TEACHER, true));
        self::assertFalse(QuizTournamentEligibility::isEligible(self::ADMIN, true));
        self::assertFalse(QuizTournamentEligibility::isEligible(self::TUTOR, true));
    }

    public function testATournamentIsInProgressOnceOpenedAndBetweenItsDates(): void
    {
        $start = new \DateTimeImmutable('2026-11-10 08:00');
        $end = new \DateTimeImmutable('2027-02-10 18:00');

        self::assertTrue(QuizTournamentEligibility::isInProgress(true, $start, $end, new \DateTimeImmutable('2026-12-01')));
        self::assertTrue(QuizTournamentEligibility::isInProgress(true, $start, $end, $start));
        self::assertFalse(QuizTournamentEligibility::isInProgress(true, $start, $end, $end), 'the end is the first moment it is over');
        self::assertFalse(QuizTournamentEligibility::isInProgress(true, $start, $end, new \DateTimeImmutable('2026-11-09')));
        self::assertFalse(QuizTournamentEligibility::isInProgress(false, $start, $end, new \DateTimeImmutable('2026-12-01')), 'a closed tournament never runs');
    }

    public function testAnEligibleStudentPlaysOnlyWhileTheTournamentRuns(): void
    {
        self::assertTrue(QuizTournamentEligibility::canPlay(self::STUDENT, true, true, true));
        self::assertFalse(QuizTournamentEligibility::canPlay(self::STUDENT, true, true, false));
        self::assertFalse(QuizTournamentEligibility::canPlay(self::STUDENT, true, false, true));
        self::assertFalse(QuizTournamentEligibility::canPlay(self::STUDENT, false, true, true), 'the feature switched off');
    }

    public function testNobodyButAnEligibleStudentPlays(): void
    {
        self::assertFalse(QuizTournamentEligibility::canPlay(self::ADMIN, true, true, true));
        self::assertFalse(QuizTournamentEligibility::canPlay(self::TEACHER, true, true, true));
    }

    public function testOnlyTheAdministratorManages(): void
    {
        self::assertTrue(QuizTournamentEligibility::canManage(self::ADMIN));
        self::assertFalse(QuizTournamentEligibility::canManage(self::STAFF));
        self::assertFalse(QuizTournamentEligibility::canManage(self::STAFF_LEAD));
        self::assertFalse(QuizTournamentEligibility::canManage(self::TEACHER));
    }

    public function testAClosedTournamentIsReadByTheAdministratorAlone(): void
    {
        self::assertTrue(QuizTournamentEligibility::canView(self::ADMIN, false, false, false, false, false));
        self::assertFalse(QuizTournamentEligibility::canView(self::STUDENT, true, false, false, true, false));
        self::assertFalse(QuizTournamentEligibility::canView(self::TEACHER, true, false, false, false, true));
        self::assertFalse(QuizTournamentEligibility::canView(self::STAFF, true, false, false, false, false));
    }

    public function testAnOpenedTournamentIsReadByTheTableOfParagraphTwo(): void
    {
        self::assertTrue(QuizTournamentEligibility::canView(self::STUDENT, true, true, false, true, false), 'an eligible student');
        self::assertFalse(QuizTournamentEligibility::canView(self::STUDENT, true, true, false, false, false), 'a student it does not reach');
        self::assertTrue(QuizTournamentEligibility::canView(self::TEACHER, true, true, false, false, true), 'a teacher of a targeted formation');
        self::assertFalse(QuizTournamentEligibility::canView(self::TEACHER, true, true, false, false, false), 'any other teacher');
        self::assertTrue(QuizTournamentEligibility::canView(self::STAFF, true, true, false, false, false));
        self::assertTrue(QuizTournamentEligibility::canView(self::STAFF_LEAD, true, true, false, false, false));
        self::assertTrue(QuizTournamentEligibility::canView(self::TEACHER, true, true, true, false, false), 'its creator');
        self::assertFalse(QuizTournamentEligibility::canView(self::TUTOR, true, true, false, true, true));
    }

    public function testTheFeatureSwitchedOffHidesItFromEveryoneButTheAdministrator(): void
    {
        self::assertFalse(QuizTournamentEligibility::canView(self::STUDENT, false, true, false, true, false));
        self::assertFalse(QuizTournamentEligibility::canView(self::STAFF, false, true, false, false, false));
        self::assertTrue(QuizTournamentEligibility::canView(self::ADMIN, false, true, false, false, false));
    }

    public function testARoundIsOpenedByATeacherOfThatTargetedFormationWhileTheTournamentRuns(): void
    {
        self::assertTrue(QuizTournamentEligibility::canOpenRound(self::TEACHER, true, true, true));
        self::assertFalse(QuizTournamentEligibility::canOpenRound(self::TEACHER, true, true, false), 'not their formation');
        self::assertFalse(QuizTournamentEligibility::canOpenRound(self::TEACHER, true, false, true), 'not running');
        self::assertFalse(QuizTournamentEligibility::canOpenRound(self::TEACHER, false, true, true), 'feature off');
        // The administration does not run a class: the round needs the teacher of that class.
        self::assertFalse(QuizTournamentEligibility::canOpenRound(self::ADMIN, true, true, false));
        self::assertFalse(QuizTournamentEligibility::canOpenRound(self::STUDENT, true, true, true));
    }
}
