<?php

declare(strict_types=1);

namespace App\Tests\Service\SeriousGame;

use App\Service\SeriousGame\SeriousGameRules;
use PHPUnit\Framework\TestCase;

/**
 * Who plays, collects and reads in the serious games (design/validated/serious-games.md §3.2), on
 * primitives - one test per decision of §1 that the rule carries.
 */
class SeriousGameRulesTest extends TestCase
{
    private const array STUDENT = ['ROLE_USER', 'ROLE_STUDENT'];
    private const array TEACHER = ['ROLE_USER', 'ROLE_TEACHER'];
    private const array STAFF = ['ROLE_USER', 'ROLE_STAFF'];
    private const array STAFF_LEAD = ['ROLE_USER', 'ROLE_STAFF-LEAD'];
    private const array ADMIN = ['ROLE_USER', 'ROLE_ADMIN'];
    private const array TUTOR = ['ROLE_USER', 'ROLE_TUTOR'];
    private const array EXTERNAL = ['ROLE_USER', 'ROLE_EXTERNAL'];

    public function testAGameIsPlayableWhenTheModuleAndTheGameAreLitForOneOfTheRoles(): void
    {
        self::assertTrue(SeriousGameRules::canPlay(self::STUDENT, true, true, ['ROLE_STUDENT' => true]));
        self::assertTrue(SeriousGameRules::canPlay(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_ECO'], true, true, ['ROLE_ECO' => true]));
    }

    public function testTheModuleSwitchedOffClosesEveryGame(): void
    {
        self::assertFalse(SeriousGameRules::canPlay(self::STUDENT, false, true, ['ROLE_STUDENT' => true]));
    }

    public function testAGameUnlitForTheRoleIsNotPlayable(): void
    {
        self::assertFalse(SeriousGameRules::canPlay(self::STUDENT, true, true, ['ROLE_STUDENT' => false, 'ROLE_TEACHER' => true]));
        self::assertFalse(SeriousGameRules::canPlay(self::STUDENT, true, true, []));
    }

    public function testAnUnpublishedGameIsPlayedByTheAdministratorAlone(): void
    {
        self::assertFalse(SeriousGameRules::canPlay(self::TEACHER, true, false, ['ROLE_TEACHER' => true]));
        self::assertTrue(SeriousGameRules::canPlay(self::ADMIN, false, false, []));
    }

    public function testATutorOrAnExternalAccountNeverPlaysWhateverTheMatrixSays(): void
    {
        self::assertFalse(SeriousGameRules::canPlay(self::TUTOR, true, true, ['ROLE_TUTOR' => true]));
        self::assertFalse(SeriousGameRules::canPlay(self::EXTERNAL, true, true, ['ROLE_EXTERNAL' => true]));
    }

    public function testOnlyAStudentCollects(): void
    {
        self::assertTrue(SeriousGameRules::canCollect(self::STUDENT));
        self::assertFalse(SeriousGameRules::canCollect(self::TEACHER));
        self::assertFalse(SeriousGameRules::canCollect(self::STAFF));
        self::assertFalse(SeriousGameRules::canCollect(self::ADMIN));
        // An adult who also carries the student role plays as the adult they are.
        self::assertFalse(SeriousGameRules::canCollect(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_TEACHER']));
    }

    public function testAStudentReadsTheirOwnResults(): void
    {
        self::assertTrue(SeriousGameRules::canReadStudent(self::STUDENT, 7, 7, false));
    }

    public function testAnotherStudentNeverReadsThem(): void
    {
        self::assertFalse(SeriousGameRules::canReadStudent(self::STUDENT, 8, 7, false));
    }

    public function testATeacherOfAnActiveClassOfTheStudentReadsThem(): void
    {
        self::assertTrue(SeriousGameRules::canReadStudent(self::TEACHER, 2, 7, true));
        self::assertFalse(SeriousGameRules::canReadStudent(self::TEACHER, 2, 7, false));
    }

    public function testTheAdministratorReadsEveryStudent(): void
    {
        self::assertTrue(SeriousGameRules::canReadStudent(self::ADMIN, 1, 7, false));
    }

    public function testStaffReadsNoStudentByName(): void
    {
        self::assertFalse(SeriousGameRules::canReadStudent(self::STAFF, 3, 7, false));
        self::assertFalse(SeriousGameRules::canReadStudent(self::STAFF_LEAD, 3, 7, false));
        // Even one who teaches reads only as a teacher, through the class.
        self::assertTrue(SeriousGameRules::canReadStudent(['ROLE_USER', 'ROLE_STAFF', 'ROLE_TEACHER'], 3, 7, true));
    }

    public function testATutorReadsNothingEvenWhenAttachedToTheClass(): void
    {
        self::assertFalse(SeriousGameRules::canReadStudent(self::TUTOR, 4, 7, true));
        self::assertFalse(SeriousGameRules::canReadStudent(self::EXTERNAL, 4, 7, true));
        self::assertFalse(SeriousGameRules::canReadClass(self::TUTOR, true));
    }

    public function testAClassIsReadByItsTeachersAndTheAdministrator(): void
    {
        self::assertTrue(SeriousGameRules::canReadClass(self::TEACHER, true));
        self::assertFalse(SeriousGameRules::canReadClass(self::TEACHER, false));
        self::assertTrue(SeriousGameRules::canReadClass(self::ADMIN, false));
        self::assertFalse(SeriousGameRules::canReadClass(self::STAFF, false));
        self::assertFalse(SeriousGameRules::canReadClass(self::STUDENT, true));
    }

    public function testTheAggregatesAreTheAdministrationsReading(): void
    {
        self::assertTrue(SeriousGameRules::canReadAggregate(self::STAFF));
        self::assertTrue(SeriousGameRules::canReadAggregate(self::STAFF_LEAD));
        self::assertTrue(SeriousGameRules::canReadAggregate(self::ADMIN));
        self::assertFalse(SeriousGameRules::canReadAggregate(self::TEACHER));
        self::assertFalse(SeriousGameRules::canReadAggregate(self::STUDENT));
    }

    public function testOnlyTheAdministratorManages(): void
    {
        self::assertTrue(SeriousGameRules::canManage(self::ADMIN));
        self::assertFalse(SeriousGameRules::canManage(self::STAFF_LEAD));
        self::assertFalse(SeriousGameRules::canManage(self::TEACHER));
    }

    /**
     * The statistics of every game, every class, with the names (design/validated/serious-games-neko-con.md
     * §6): every teacher and the administrator; staff keep their figures without names.
     */
    public function testEveryTeacherAndTheAdministratorReadTheStatistics(): void
    {
        self::assertTrue(SeriousGameRules::canReadStats(self::TEACHER));
        self::assertTrue(SeriousGameRules::canReadStats(self::ADMIN));
        self::assertFalse(SeriousGameRules::canReadStats(self::STAFF));
        self::assertFalse(SeriousGameRules::canReadStats(self::STAFF_LEAD));
        self::assertFalse(SeriousGameRules::canReadStats(self::STUDENT));
        self::assertFalse(SeriousGameRules::canReadStats(['ROLE_USER', 'ROLE_TEACHER', 'ROLE_TUTOR']), 'a tutor reads nothing');
    }
}
