<?php

declare(strict_types=1);

namespace App\Tests\Service\SchoolYearImport;

use App\Service\SchoolYearImport\SchoolYearImportRules;
use PHPUnit\Framework\TestCase;

/**
 * R5 (which formation of the target year a source formation is completed into) and R6 (which
 * teacher is still a teacher), on primitives.
 */
class SchoolYearImportRulesTest extends TestCase
{
    private static function target(int $id, int $cohort, string $name, ?int $importedFrom = null, string $created = '2027-06-01 10:00:00'): array
    {
        return ['id' => $id, 'cohortId' => $cohort, 'name' => $name, 'importedFromId' => $importedFrom, 'created' => $created];
    }

    public function testNoCandidateMeansCreated(): void
    {
        $matches = SchoolYearImportRules::matchPrograms(
            [['id' => 1, 'cohortId' => 5, 'name' => 'BTS SIO 1']],
            [self::target(20, 6, 'BTS SIO 1')],
        );

        self::assertSame([], $matches);
    }

    public function testSameCohortAndSameNameAfterRewriting(): void
    {
        $matches = SchoolYearImportRules::matchPrograms(
            [['id' => 1, 'cohortId' => 5, 'name' => 'BTS NDRC 1 2027-2028']],
            [self::target(20, 5, '  bts ndrc 1   2027-2028 ')],
        );

        self::assertSame([1 => ['target' => 20, 'ambiguous' => false]], $matches);
    }

    public function testSameNameInAnotherCohortIsNotAMatch(): void
    {
        self::assertSame([], SchoolYearImportRules::matchPrograms(
            [['id' => 1, 'cohortId' => 5, 'name' => 'BTS SIO 1']],
            [self::target(20, 9, 'BTS SIO 1')],
        ));
    }

    public function testImportedFromWinsEvenUnderAnotherName(): void
    {
        $matches = SchoolYearImportRules::matchPrograms(
            [['id' => 1, 'cohortId' => 5, 'name' => 'BTS SIO 1']],
            [self::target(20, 5, 'BTS SIO 1', created: '2027-01-01 00:00:00'), self::target(21, 5, 'SIO renommée', 1)],
        );

        self::assertSame([1 => ['target' => 21, 'ambiguous' => true]], $matches);
    }

    public function testWithoutImportedFromTheOldestWins(): void
    {
        $matches = SchoolYearImportRules::matchPrograms(
            [['id' => 1, 'cohortId' => 5, 'name' => 'BTS SIO 1']],
            [self::target(22, 5, 'BTS SIO 1', created: '2027-06-03 00:00:00'), self::target(21, 5, 'BTS SIO 1', created: '2027-06-02 00:00:00')],
        );

        self::assertSame([1 => ['target' => 21, 'ambiguous' => true]], $matches);
    }

    public function testATargetServesOneSourceOnly(): void
    {
        $matches = SchoolYearImportRules::matchPrograms(
            [['id' => 1, 'cohortId' => 5, 'name' => 'BTS SIO 1'], ['id' => 2, 'cohortId' => 5, 'name' => 'BTS SIO 1']],
            [self::target(20, 5, 'BTS SIO 1')],
        );

        self::assertSame([1 => ['target' => 20, 'ambiguous' => false]], $matches);
    }

    public function testTeacherStillActiveAndTeacherIsTakenOver(): void
    {
        self::assertNull(SchoolYearImportRules::teacherRefusal(false, ['ROLE_TEACHER', 'ROLE_USER']));
    }

    public function testDeactivatedAccountIsNotTakenOver(): void
    {
        self::assertSame(SchoolYearImportRules::TEACHER_INACTIVE, SchoolYearImportRules::teacherRefusal(true, ['ROLE_TEACHER']));
    }

    public function testNoLongerTeacherIsNotTakenOver(): void
    {
        self::assertSame(SchoolYearImportRules::TEACHER_NOT_TEACHER, SchoolYearImportRules::teacherRefusal(false, ['ROLE_STAFF', 'ROLE_USER']));
    }

    public function testKeysIgnoreCaseAndSpaces(): void
    {
        self::assertSame('mathématiques appliquées', SchoolYearImportRules::key("  Mathématiques \t Appliquées "));
    }

    public function testExamSessionMovesByTheYearsBetweenTheTwoStarts(): void
    {
        self::assertSame(2029, SchoolYearImportRules::examSession(2028, 2026, 2027));
        self::assertSame(2027, SchoolYearImportRules::examSession(2028, 2027, 2026));
        self::assertNull(SchoolYearImportRules::examSession(null, 2026, 2027));
    }
}
