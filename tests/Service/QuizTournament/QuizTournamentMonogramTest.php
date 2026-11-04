<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Service\QuizTournament\QuizTournamentMonogram;
use PHPUnit\Framework\TestCase;

/**
 * The monogram that stands in for a tournament's logo (design/validated/quiz-tournament.md §10.1):
 * drawn from the name, without the word « tournoi » every name carries.
 */
class QuizTournamentMonogramTest extends TestCase
{
    public function testAnAcronymInTheNameIsTheMonogram(): void
    {
        self::assertSame('RGPD', QuizTournamentMonogram::of('Tournoi RGPD'));
        self::assertSame('SQL', QuizTournamentMonogram::of('Tournoi de SQL'));
        self::assertSame('SISR', QuizTournamentMonogram::of('Le grand tournoi SISR 2027'));
    }

    public function testOneWordGivesItsFirstThreeLetters(): void
    {
        self::assertSame('RÉS', QuizTournamentMonogram::of('Tournoi Réseau'));
        self::assertSame('CYB', QuizTournamentMonogram::of('cybersécurité'));
    }

    public function testSeveralWordsGiveTheirInitials(): void
    {
        self::assertSame('BD', QuizTournamentMonogram::of('Tournoi des bases de données'));
        self::assertSame('HCA', QuizTournamentMonogram::of("Histoire, culture et art de l'Europe"));
    }

    public function testANameMadeOfStopWordsFallsBackOnItsOwnLetters(): void
    {
        self::assertSame('TOU', QuizTournamentMonogram::of('Tournoi'));
        self::assertSame('?', QuizTournamentMonogram::of('  '));
    }
}
