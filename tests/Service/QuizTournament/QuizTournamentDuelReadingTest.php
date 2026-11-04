<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Service\QuizTournament\QuizTournamentDuelReading;
use PHPUnit\Framework\TestCase;

/**
 * What the end-of-duel screens read off a finished duel (design/validated/quiz-tournament.md §5.3,
 * §5.4, §10), on primitives: the score, the defeat's consolation, the errors by theme, the rating
 * line of « Mes duels ».
 */
class QuizTournamentDuelReadingTest extends TestCase
{
    public function testTheScoreIsTheSumOfThePointsAnUnansweredQuestionCountingZero(): void
    {
        self::assertSame(2.5, QuizTournamentDuelReading::score([1.0, null, 0.5, 1.0, 0.0]));
        self::assertSame(0.0, QuizTournamentDuelReading::score([]));
    }

    public function testADefeatAgainstAStrongerPlayerSaysWhatItCostAndWhatAWinWouldHaveBrought(): void
    {
        // 1052 lost to 1098 in their 12th duel (K 20).
        $consolation = QuizTournamentDuelReading::consolation(1052, 1044, 1098, 11);

        self::assertNotNull($consolation);
        self::assertSame(46, $consolation['gap']);
        self::assertSame(8, $consolation['cost']);
        self::assertSame(11, $consolation['wouldWin']); // 20 × (1 − 0.434)
    }

    public function testNoConsolationAgainstAWeakerOrEqualPlayer(): void
    {
        self::assertNull(QuizTournamentDuelReading::consolation(1052, 1040, 1020, 11));
        self::assertNull(QuizTournamentDuelReading::consolation(1000, 980, 1000, 0));
    }

    public function testTheErrorsAreGroupedByTheirQuizMostFirst(): void
    {
        $themes = QuizTournamentDuelReading::errorsByTheme([
            ['theme' => 'Violation de données', 'points' => 0.0],
            ['theme' => 'Durées de conservation', 'points' => null],
            ['theme' => 'Violation de données', 'points' => 1.0],
            ['theme' => 'Collecte', 'points' => 0.0],
            ['theme' => 'Violation de données', 'points' => 0.0],
            ['theme' => 'Durées de conservation', 'points' => 0.0],
            ['theme' => 'Collecte', 'points' => 0.5],
        ]);

        self::assertSame([
            ['theme' => 'Collecte', 'count' => 2],
            ['theme' => 'Durées de conservation', 'count' => 2],
            ['theme' => 'Violation de données', 'count' => 2],
        ], $themes);
    }

    public function testAQuestionIsAnErrorUnlessItEarnedEverything(): void
    {
        self::assertTrue(QuizTournamentDuelReading::isError(null));
        self::assertTrue(QuizTournamentDuelReading::isError(0.0));
        self::assertTrue(QuizTournamentDuelReading::isError(0.5));
        self::assertFalse(QuizTournamentDuelReading::isError(1.0));
    }

    public function testTheRatingLineStartsAt1000AndKeepsItsBest(): void
    {
        $line = QuizTournamentDuelReading::ratingLine([1012, 990, 1031, 1020]);

        self::assertSame([1000, 1012, 990, 1031, 1020], $line['points']);
        self::assertSame(1031, $line['best']);
        self::assertSame(20, $line['sinceStart']);

        $empty = QuizTournamentDuelReading::ratingLine([]);
        self::assertSame([1000], $empty['points']);
        self::assertSame(1000, $empty['best']);
        self::assertSame(0, $empty['sinceStart']);
    }

    public function testTheChallengesLeftTodayFollowTheDailyCap(): void
    {
        self::assertSame(1, QuizTournamentDuelReading::challengesLeftToday(2));
        self::assertSame(0, QuizTournamentDuelReading::challengesLeftToday(3));
        self::assertSame(0, QuizTournamentDuelReading::challengesLeftToday(5));
    }

    public function testAnExplanationIsReadAsItsWhyItsExampleAndItsSource(): void
    {
        $parts = QuizTournamentDuelReading::explanationParts("Le délai court à partir de la connaissance.\nLe week-end ne le suspend pas.\n\nExemple : Une intrusion révélée le vendredi.\n\nSource : RGPD, art. 33, 1");

        self::assertSame(["Le délai court à partir de la connaissance.\nLe week-end ne le suspend pas."], $parts['why']);
        self::assertSame('Une intrusion révélée le vendredi.', $parts['example']);
        self::assertSame('Source : RGPD, art. 33, 1', $parts['source']);
    }

    public function testAnExplanationWithoutThoseHeadingsIsAllWhy(): void
    {
        $parts = QuizTournamentDuelReading::explanationParts("Premier paragraphe.\r\n\r\nSecond paragraphe.");

        self::assertSame(['Premier paragraphe.', 'Second paragraphe.'], $parts['why']);
        self::assertNull($parts['example']);
        self::assertNull($parts['source']);
        self::assertSame(['why' => [], 'example' => null, 'source' => null], QuizTournamentDuelReading::explanationParts(null));
    }
}
