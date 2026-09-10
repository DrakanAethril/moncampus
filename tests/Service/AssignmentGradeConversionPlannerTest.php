<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\AssignmentMissingGradeChoice;
use App\Enum\GradeStatus;
use App\Service\AssignmentGradeConversionPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind « Convertir en note », kept on primitives so it can be read without
 * mounting a quiz, a class and a gradebook: what mark each student ends up with, out of the barème
 * the teacher chose.
 *
 * The denominator is the attempt's, never the barème: with « mêmes questions pour tous » open each
 * student is drawn their own set, and a weighted question makes two draws add up to different
 * totals. So the conversion is a rule of three per student and not a copy of the points earned.
 */
class AssignmentGradeConversionPlannerTest extends TestCase
{
    public function testMarkIsRescaledFromTheAttemptsOwnTotalToTheChosenScale(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [['studentId' => 7, 'pointsEarned' => 12.0, 'pointsAvailable' => 15]],
            20.0,
            [],
        );

        self::assertSame([], $plan->unresolvedStudentIds);
        self::assertSame(GradeStatus::Normal, $plan->cells[0]['status']);
        self::assertSame(16.0, $plan->cells[0]['value']);
    }

    /** A barème equal to the quiz's own total leaves the mark exactly as the quiz counted it. */
    public function testMarkIsLeftUntouchedWhenTheScaleMatchesTheQuiz(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [['studentId' => 7, 'pointsEarned' => 12.0, 'pointsAvailable' => 15]],
            15.0,
            [],
        );

        self::assertSame(12.0, $plan->cells[0]['value']);
    }

    /** Two students drawn different totals are each read against their own. */
    public function testEachStudentIsReadAgainstTheirOwnDraw(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [
                ['studentId' => 1, 'pointsEarned' => 10.0, 'pointsAvailable' => 20],
                ['studentId' => 2, 'pointsEarned' => 10.0, 'pointsAvailable' => 25],
            ],
            20.0,
            [],
        );

        self::assertSame(10.0, $plan->cells[0]['value']);
        self::assertSame(8.0, $plan->cells[1]['value']);
    }

    /** A partially scored texte à trous gives a fractional mark; it is rounded, never truncated. */
    public function testFractionalMarksAreRoundedToTwoDecimals(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [['studentId' => 1, 'pointsEarned' => 13.67, 'pointsAvailable' => 15]],
            20.0,
            [],
        );

        self::assertSame(18.23, $plan->cells[0]['value']);
    }

    public function testEachMissingStudentIsWrittenAsTheTeacherAskedForThem(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [
                ['studentId' => 1, 'pointsEarned' => null, 'pointsAvailable' => null],
                ['studentId' => 2, 'pointsEarned' => null, 'pointsAvailable' => null],
                ['studentId' => 3, 'pointsEarned' => null, 'pointsAvailable' => null],
            ],
            20.0,
            [
                1 => AssignmentMissingGradeChoice::Zero,
                2 => AssignmentMissingGradeChoice::Absent,
                3 => AssignmentMissingGradeChoice::NotEvaluated,
            ],
        );

        self::assertSame([], $plan->unresolvedStudentIds);
        self::assertSame(GradeStatus::Normal, $plan->cells[0]['status']);
        self::assertSame(0.0, $plan->cells[0]['value']);
        self::assertSame(GradeStatus::Absent, $plan->cells[1]['status']);
        self::assertNull($plan->cells[1]['value']);
        self::assertSame(GradeStatus::NotEvaluated, $plan->cells[2]['status']);
        self::assertNull($plan->cells[2]['value']);
    }

    /**
     * No default is invented for a student nobody answered for: a zero is a mark, an absence is a
     * fact about the day, and « non évalué » is neither. The planner names them and writes nothing.
     */
    public function testAStudentWithNoChoiceIsReportedRatherThanGuessedAt(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [
                ['studentId' => 1, 'pointsEarned' => 12.0, 'pointsAvailable' => 15],
                ['studentId' => 2, 'pointsEarned' => null, 'pointsAvailable' => null],
            ],
            20.0,
            [],
        );

        self::assertSame([2], $plan->unresolvedStudentIds);
        self::assertCount(1, $plan->cells);
        self::assertSame(1, $plan->cells[0]['studentId']);
    }

    /**
     * A choice sent for a student who did sit the quiz changes nothing - the quiz is the source of
     * the mark. That is what makes a re-conversion catch the latecomer up: the row that was written
     * « Absent » a week ago becomes their real mark.
     */
    public function testAnAttemptAlwaysWinsOverAChoiceSentForTheSameStudent(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [['studentId' => 1, 'pointsEarned' => 9.0, 'pointsAvailable' => 15]],
            20.0,
            [1 => AssignmentMissingGradeChoice::Absent],
        );

        self::assertSame(GradeStatus::Normal, $plan->cells[0]['status']);
        self::assertSame(12.0, $plan->cells[0]['value']);
    }

    /**
     * An attempt whose total is missing or zero - an attempt concluded before the score column
     * existed - cannot be turned into a fraction. It is left to the teacher rather than counted
     * as a zero, which is the one reading that would invent a mark.
     */
    public function testAnAttemptWithNoTotalIsTreatedAsAStudentToDecideOn(): void
    {
        $plan = (new AssignmentGradeConversionPlanner())->plan(
            [['studentId' => 4, 'pointsEarned' => 3.0, 'pointsAvailable' => 0]],
            20.0,
            [],
        );

        self::assertSame([4], $plan->unresolvedStudentIds);
        self::assertSame([], $plan->cells);
    }
}
