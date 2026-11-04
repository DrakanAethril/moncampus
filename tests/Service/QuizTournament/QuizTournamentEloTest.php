<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentPlayer;
use App\Entity\User;
use App\Enum\QuizTournamentPalette;
use App\Service\QuizTournament\QuizTournamentElo;
use PHPUnit\Framework\TestCase;

/**
 * The tournament's rating (design/validated/quiz-tournament.md §6.1), on primitives: the chess
 * formula, K 40 for a player's first ten duels and 20 afterwards, rounded to the integer.
 */
class QuizTournamentEloTest extends TestCase
{
    public function testEveryPlayerStartsAt1000(): void
    {
        $player = new QuizTournamentPlayer(
            new QuizTournament('Tournoi', new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2027-02-10'), QuizTournamentPalette::Lavender, new User('admin')),
            new User('student'),
        );

        self::assertSame(1000, $player->getRating());
        self::assertSame(0, $player->getDuels());
    }

    public function testAFinishedDuelMovesTheLine(): void
    {
        $player = new QuizTournamentPlayer(
            new QuizTournament('Tournoi', new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2027-02-10'), QuizTournamentPalette::Lavender, new User('admin')),
            new User('student'),
        );
        $player->recordDuel(1020, 1.0)->recordDuel(1015, 0.5)->recordDuel(990, 0.0);

        self::assertSame(990, $player->getRating());
        self::assertSame([3, 1, 1, 1], [$player->getDuels(), $player->getWins(), $player->getDraws(), $player->getLosses()]);
    }

    public function testTheExpectedScoreIsTheChessFormula(): void
    {
        self::assertEqualsWithDelta(0.5, QuizTournamentElo::expectedScore(1000, 1000), 1e-9);
        self::assertEqualsWithDelta(1 / (1 + 10 ** (-200 / 400)), QuizTournamentElo::expectedScore(1200, 1000), 1e-9);
        self::assertEqualsWithDelta(1.0, QuizTournamentElo::expectedScore(1200, 1000) + QuizTournamentElo::expectedScore(1000, 1200), 1e-9);
    }

    public function testKIs40ForTheFirstTenDuelsThen20(): void
    {
        self::assertSame(40, QuizTournamentElo::kFactor(0));
        self::assertSame(40, QuizTournamentElo::kFactor(9), 'the tenth duel is still a placement one');
        self::assertSame(20, QuizTournamentElo::kFactor(10));
        self::assertSame(20, QuizTournamentElo::kFactor(57));
    }

    public function testANewcomerWinningAgainstAnEqualGains20(): void
    {
        self::assertSame(1020, QuizTournamentElo::newRating(1000, 1000, 1.0, 0));
        self::assertSame(980, QuizTournamentElo::newRating(1000, 1000, 0.0, 0));
        self::assertSame(1000, QuizTournamentElo::newRating(1000, 1000, 0.5, 0));
    }

    public function testASettledPlayerMovesHalfAsMuch(): void
    {
        self::assertSame(1010, QuizTournamentElo::newRating(1000, 1000, 1.0, 10));
    }

    public function testTheNewRatingIsRounded(): void
    {
        // E = 0.759746..., K 20: a win adds 4.805, rounded to 5; a loss takes 15.19, rounded to 15.
        self::assertSame(1205, QuizTournamentElo::newRating(1200, 1000, 1.0, 20));
        self::assertSame(1185, QuizTournamentElo::newRating(1200, 1000, 0.0, 20));
        // A draw against a weaker player costs: 20 × (0.5 − 0.7597) = −5.19.
        self::assertSame(1195, QuizTournamentElo::newRating(1200, 1000, 0.5, 20));
    }

    public function testMorePointsWinsEqualPointsDraw(): void
    {
        self::assertSame(1.0, QuizTournamentElo::outcome(14.5, 12.0));
        self::assertSame(0.0, QuizTournamentElo::outcome(9.0, 12.0));
        self::assertSame(0.5, QuizTournamentElo::outcome(12.0, 12.0));
        // Partial credit adds decimals: a float's noise is not a victory.
        self::assertSame(0.5, QuizTournamentElo::outcome(0.1 + 0.2, 0.3));
    }

    public function testTheStakesShowWhatADuelWouldBringOrCost(): void
    {
        self::assertSame(['win' => 20, 'draw' => 0, 'loss' => -20], QuizTournamentElo::stakes(1000, 1000, 3));
        self::assertSame(['win' => 15, 'draw' => 5, 'loss' => -5], QuizTournamentElo::stakes(1000, 1200, 20));
    }

    public function testBothRatingsMoveTogether(): void
    {
        self::assertSame([1020, 980], QuizTournamentElo::rate(1000, 1000, 15.0, 11.0, 0, 0));
        // Each side has its own K: the newcomer moves twice as far as the settled player.
        self::assertSame([980, 1010], QuizTournamentElo::rate(1000, 1000, 8.0, 11.0, 4, 30));
        self::assertSame([1000, 1000], QuizTournamentElo::rate(1000, 1000, 10.0, 10.0, 4, 30));
    }
}
