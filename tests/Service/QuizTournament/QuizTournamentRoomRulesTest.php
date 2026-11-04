<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuizTournamentRoomRefusal;
use App\Service\QuizTournament\QuizTournamentRoomFacts;
use App\Service\QuizTournament\QuizTournamentRoomRules;
use PHPUnit\Framework\TestCase;

/**
 * The room of an accepted challenge (design/validated/quiz-tournament.md §4.3), on primitives: who
 * may click « Prêt », and when the duel starts - both ready, both still eligible, neither playing
 * elsewhere, once.
 */
class QuizTournamentRoomRulesTest extends TestCase
{
    public function testAPlayerInAnOpenRoomMayBeReady(): void
    {
        self::assertNull(QuizTournamentRoomRules::readyRefusal($this->facts()));
    }

    public function testReadyIsRefusedWhileEitherPlaysElsewhere(): void
    {
        self::assertSame(QuizTournamentRoomRefusal::PlayerBusy, QuizTournamentRoomRules::readyRefusal($this->facts(playerBusy: true)));
        self::assertSame(QuizTournamentRoomRefusal::OpponentBusy, QuizTournamentRoomRules::readyRefusal($this->facts(opponentBusy: true)));
    }

    public function testReadyIsRefusedOnceTheRoomOrTheTournamentIsOverOrThePlayerIneligible(): void
    {
        self::assertSame(QuizTournamentRoomRefusal::RoomClosed, QuizTournamentRoomRules::readyRefusal($this->facts(roomOpen: false)));
        self::assertSame(QuizTournamentRoomRefusal::NotInProgress, QuizTournamentRoomRules::readyRefusal($this->facts(inProgress: false)));
        self::assertSame(QuizTournamentRoomRefusal::PlayerNotEligible, QuizTournamentRoomRules::readyRefusal($this->facts(playerEligible: false)));
        // An opponent who lost their eligibility does not stop anybody from waiting: the start refuses.
        self::assertNull(QuizTournamentRoomRules::readyRefusal($this->facts(opponentEligible: false)));
    }

    public function testTheDuelStartsWhenBothAreReady(): void
    {
        self::assertTrue(QuizTournamentRoomRules::shouldStart($this->facts(playerReady: true, opponentReady: true)));
        self::assertFalse(QuizTournamentRoomRules::shouldStart($this->facts(playerReady: true, opponentReady: false)));
        self::assertFalse(QuizTournamentRoomRules::shouldStart($this->facts(playerReady: false, opponentReady: true)));
    }

    public function testADuelStartsOnlyOnce(): void
    {
        self::assertFalse(QuizTournamentRoomRules::shouldStart($this->facts(playerReady: true, opponentReady: true, started: true)));
    }

    public function testTheStartRereadsEligibilityAndTheOtherDuels(): void
    {
        $both = ['playerReady' => true, 'opponentReady' => true];

        self::assertSame(QuizTournamentRoomRefusal::OpponentNotEligible, QuizTournamentRoomRules::startRefusal($this->facts(...$both, opponentEligible: false)));
        self::assertSame(QuizTournamentRoomRefusal::OpponentBusy, QuizTournamentRoomRules::startRefusal($this->facts(...$both, opponentBusy: true)));
        self::assertSame(QuizTournamentRoomRefusal::RoomClosed, QuizTournamentRoomRules::startRefusal($this->facts(...$both, roomOpen: false)));
        self::assertFalse(QuizTournamentRoomRules::shouldStart($this->facts(...$both, opponentEligible: false)));
        self::assertFalse(QuizTournamentRoomRules::shouldStart($this->facts(...$both, playerBusy: true)));
        self::assertFalse(QuizTournamentRoomRules::shouldStart($this->facts(...$both, inProgress: false)));
    }

    private function facts(
        bool $roomOpen = true,
        bool $inProgress = true,
        bool $started = false,
        bool $playerReady = false,
        bool $opponentReady = false,
        bool $playerEligible = true,
        bool $opponentEligible = true,
        bool $playerBusy = false,
        bool $opponentBusy = false,
    ): QuizTournamentRoomFacts {
        return new QuizTournamentRoomFacts($roomOpen, $inProgress, $started, $playerReady, $opponentReady, $playerEligible, $opponentEligible, $playerBusy, $opponentBusy);
    }
}
