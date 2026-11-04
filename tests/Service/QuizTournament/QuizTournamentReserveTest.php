<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuestionType;
use App\Service\QuizTournament\QuizTournamentReserve;
use PHPUnit\Framework\TestCase;

/**
 * The save-time check of a tournament's reserve (design/validated/quiz-tournament.md §3), on
 * primitives: which questions of the source quizzes a duel may play, which it ignores, and what is
 * missing for a full duel.
 */
class QuizTournamentReserveTest extends TestCase
{
    public function testOnlyTheDuelFormatsAreAdmitted(): void
    {
        $reserve = QuizTournamentReserve::of([
            QuestionType::Qcm, QuestionType::QcmMulti, QuestionType::VraiFaux, QuestionType::Numerique,
            QuestionType::Apparier, QuestionType::Ordre, QuestionType::Apparier,
        ], 3, 1);

        self::assertSame(4, $reserve->admitted);
        self::assertSame(3, $reserve->admittedNotTrueFalse);
        self::assertSame(3, $reserve->ignoredCount());
        self::assertSame(['apparier' => 2, 'ordre' => 1], $reserve->ignored);
    }

    public function testTheIgnoredTypesAreListedMostFrequentFirst(): void
    {
        $reserve = QuizTournamentReserve::of([QuestionType::Ordre, QuestionType::Apparier, QuestionType::Apparier, QuestionType::Zone], 1, 1);

        self::assertSame([QuestionType::Apparier, QuestionType::Ordre, QuestionType::Zone], $reserve->ignoredTypes());
    }

    public function testAReserveLargeEnoughAllowsAFullDuel(): void
    {
        $types = [...array_fill(0, 15, QuestionType::Qcm), ...array_fill(0, 5, QuestionType::VraiFaux)];

        $reserve = QuizTournamentReserve::of($types, 20, 5);

        self::assertTrue($reserve->allowsAFullDuel());
        self::assertSame(0, $reserve->missing);
        self::assertSame(0, $reserve->missingNotTrueFalse);
    }

    public function testTooFewQuestionsAreCounted(): void
    {
        $reserve = QuizTournamentReserve::of(array_fill(0, 12, QuestionType::Qcm), 20, 5);

        self::assertFalse($reserve->allowsAFullDuel());
        self::assertSame(8, $reserve->missing);
        self::assertSame(3, $reserve->missingNotTrueFalse);
    }

    public function testTooManyTrueFalseLeaveTheDuelShortOfOtherQuestions(): void
    {
        // 30 admitted, but a duel takes at most 5 vrai/faux: 15 others are needed, 10 are there.
        $types = [...array_fill(0, 10, QuestionType::Qcm), ...array_fill(0, 20, QuestionType::VraiFaux)];

        $reserve = QuizTournamentReserve::of($types, 20, 5);

        self::assertFalse($reserve->allowsAFullDuel());
        self::assertSame(0, $reserve->missing);
        self::assertSame(5, $reserve->missingNotTrueFalse);
    }

    public function testAnEmptyReserveMissesEverything(): void
    {
        $reserve = QuizTournamentReserve::of([], 20, 5);

        self::assertSame(20, $reserve->missing);
        self::assertSame(15, $reserve->missingNotTrueFalse);
        self::assertSame([], $reserve->ignoredTypes());
    }
}
