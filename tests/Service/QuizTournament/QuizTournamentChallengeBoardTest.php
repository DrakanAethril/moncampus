<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\User;
use App\Enum\QuizTournamentPalette;
use App\Service\QuizTournament\QuizTournamentChallengeBoard;
use PHPUnit\Framework\TestCase;

/**
 * « Mes défis » (design/validated/quiz-tournament.md §7): the open rooms first - the one where the
 * opponent already waits at the top -, then the challenges received, then those sent; and the tab's
 * counter, received + rooms. Read at a moment: a challenge past its 24 hours is no longer pending.
 */
class QuizTournamentChallengeBoardTest extends TestCase
{
    private QuizTournament $tournament;
    private User $me;
    private User $hugo;
    private User $lucas;
    private User $maelys;
    private User $theo;

    protected function setUp(): void
    {
        $this->tournament = new QuizTournament('Tournoi RGPD', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2027-02-01'), QuizTournamentPalette::Lavender, new User('admin'));
        $this->me = new User('nathan');
        $this->hugo = new User('hugo');
        $this->lucas = new User('lucas');
        $this->maelys = new User('maelys');
        $this->theo = new User('theo');
    }

    public function testRoomsComeFirstWithTheWaitingOpponentAtTheTop(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 14:00:00');
        $closingSoon = $this->challenge($this->me, $this->lucas, '2026-11-19 10:00')->accept(new \DateTimeImmutable('2026-11-19 16:00'));
        $waiting = $this->challenge($this->hugo, $this->me, '2026-11-20 07:00')->accept(new \DateTimeImmutable('2026-11-20 08:00'));
        $waiting->markReady($this->hugo, $now->modify('-10 seconds'));

        $board = QuizTournamentChallengeBoard::of([$closingSoon, $waiting], $this->me, $now);

        self::assertSame([$waiting, $closingSoon], $board->rooms);
        self::assertTrue($board->isOpponentWaiting($waiting, $now));
        self::assertFalse($board->isOpponentWaiting($closingSoon, $now));
    }

    public function testReceivedChallengesSoonestToExpireFirstAndTheCounter(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 14:00:00');
        $lucas = $this->challenge($this->lucas, $this->me, '2026-11-20 11:00');
        $maelys = $this->challenge($this->maelys, $this->me, '2026-11-19 17:00');
        $lapsed = $this->challenge($this->theo, $this->me, '2026-11-19 13:00');
        $room = $this->challenge($this->hugo, $this->me, '2026-11-20 07:00')->accept(new \DateTimeImmutable('2026-11-20 08:00'));

        $board = QuizTournamentChallengeBoard::of([$lucas, $maelys, $lapsed, $room], $this->me, $now);

        self::assertSame([$maelys, $lucas], $board->received, 'an unanswered challenge past 24 h is no longer offered');
        self::assertSame(3, $board->count(), 'two received, one room');
        self::assertSame(3, QuizTournamentChallengeBoard::countFor([$lucas, $maelys, $lapsed, $room], $this->me, $now));
    }

    public function testSentChallengesShowWhileTheyWaitAndTheirOutcomeForADay(): void
    {
        $now = new \DateTimeImmutable('2026-11-20 14:00:00');
        $pending = $this->challenge($this->me, $this->theo, '2026-11-20 13:55');
        $refused = $this->challenge($this->me, $this->lucas, '2026-11-20 09:00')->refuse(new \DateTimeImmutable('2026-11-20 10:00'));
        $oldRefusal = $this->challenge($this->me, $this->maelys, '2026-11-18 09:00')->refuse(new \DateTimeImmutable('2026-11-18 10:00'));
        $expired = $this->challenge($this->me, $this->hugo, '2026-11-19 12:00');

        $board = QuizTournamentChallengeBoard::of([$pending, $refused, $oldRefusal, $expired], $this->me, $now);

        self::assertSame([$pending, $refused, $expired], $board->sent);
        self::assertSame(0, $board->count(), 'what one sent never counts');
    }

    public function testNothingIsOpenOnceTheTournamentIsOver(): void
    {
        $now = new \DateTimeImmutable('2027-02-01 00:00:01');
        $received = $this->challenge($this->lucas, $this->me, '2027-01-31 20:00');
        $room = $this->challenge($this->hugo, $this->me, '2027-01-31 18:00')->accept(new \DateTimeImmutable('2027-01-31 19:00'));

        $board = QuizTournamentChallengeBoard::of([$received, $room], $this->me, $now);

        self::assertSame([], $board->rooms);
        self::assertSame([], $board->received);
        self::assertSame(0, $board->count());
        self::assertTrue($board->isEmpty());
    }

    private function challenge(User $challenger, User $challenged, string $sentAt): QuizTournamentChallenge
    {
        return new QuizTournamentChallenge($this->tournament, $challenger, $challenged, false, new \DateTimeImmutable($sentAt));
    }
}
