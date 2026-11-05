<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelQuestion;
use App\Entity\QuizTournamentRound;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentChallengeStatus;
use App\Enum\QuizTournamentDuelOrigin;
use App\Enum\QuizTournamentPalette;
use App\Enum\QuizTournamentRoundStatus;
use PHPUnit\Framework\TestCase;

/**
 * The clocks the tournament's rows carry themselves (design/validated/quiz-tournament.md §4 and §5):
 * a challenge reads as expired, a room as missed, the moment their 24 hours are over; « Prêt » holds
 * while its heartbeat is fresh; a question's clock is set once.
 */
class QuizTournamentChallengeTest extends TestCase
{
    private User $alice;
    private User $bruno;

    protected function setUp(): void
    {
        $this->alice = new User('alice');
        $this->bruno = new User('bruno');
    }

    public function testAnUnansweredChallengeExpiresAfter24Hours(): void
    {
        $challenge = $this->challenge('2026-11-20 14:00');

        self::assertTrue($challenge->isPending(new \DateTimeImmutable('2026-11-21 13:59')));
        self::assertSame(QuizTournamentChallengeStatus::Expired, $challenge->statusAt(new \DateTimeImmutable('2026-11-21 14:00')));
        self::assertSame(QuizTournamentChallengeStatus::Pending, $challenge->getStatus(), 'nothing is rewritten by reading');
    }

    public function testAnAcceptedChallengeOpensARoomFor24Hours(): void
    {
        $challenge = $this->challenge('2026-11-20 14:00')->accept(new \DateTimeImmutable('2026-11-21 09:00'));

        self::assertTrue($challenge->isRoomOpen(new \DateTimeImmutable('2026-11-22 08:59')));
        self::assertSame(QuizTournamentChallengeStatus::Missed, $challenge->statusAt(new \DateTimeImmutable('2026-11-22 09:00')));
    }

    public function testTheTournamentsEndClosesPendingChallengesAndOpenRooms(): void
    {
        $tournament = new QuizTournament('Tournoi', new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2026-11-21 12:00'), QuizTournamentPalette::Lavender, new User('admin'));
        $pending = new QuizTournamentChallenge($tournament, $this->alice, $this->bruno, false, new \DateTimeImmutable('2026-11-21 08:00'));
        $room = (new QuizTournamentChallenge($tournament, $this->bruno, $this->alice, false, new \DateTimeImmutable('2026-11-21 07:00')))
            ->accept(new \DateTimeImmutable('2026-11-21 09:00'));

        self::assertTrue($pending->isPending(new \DateTimeImmutable('2026-11-21 11:59')));
        self::assertSame(QuizTournamentChallengeStatus::Expired, $pending->statusAt(new \DateTimeImmutable('2026-11-21 12:00')));
        self::assertTrue($room->isRoomOpen(new \DateTimeImmutable('2026-11-21 11:59')));
        self::assertSame(QuizTournamentChallengeStatus::Missed, $room->statusAt(new \DateTimeImmutable('2026-11-21 12:00')));
    }

    public function testSettlingWritesWhatTheClockAlreadySays(): void
    {
        $challenge = $this->challenge('2026-11-20 14:00');

        self::assertFalse($challenge->settle(new \DateTimeImmutable('2026-11-21 13:00')), 'still pending: nothing to write');
        self::assertTrue($challenge->settle(new \DateTimeImmutable('2026-11-21 14:00')));
        self::assertSame(QuizTournamentChallengeStatus::Expired, $challenge->getStatus());

        $room = $this->challenge('2026-11-20 14:00')->accept(new \DateTimeImmutable('2026-11-20 15:00'));
        $room->markReady($this->alice, new \DateTimeImmutable('2026-11-21 14:59:50'));
        self::assertTrue($room->settle(new \DateTimeImmutable('2026-11-21 15:00')));
        self::assertSame(QuizTournamentChallengeStatus::Missed, $room->getStatus());
        self::assertFalse($room->isReady($this->alice, new \DateTimeImmutable('2026-11-21 15:00')), 'a missed room keeps nobody ready');
    }

    public function testTheOpponentAndTheReadinessAreReadFromEitherSide(): void
    {
        $challenge = $this->challenge('2026-11-20 14:00')->accept(new \DateTimeImmutable('2026-11-20 15:00'));

        self::assertSame($this->bruno, $challenge->opponentOf($this->alice));
        self::assertSame($this->alice, $challenge->opponentOf($this->bruno));
        self::assertNull($challenge->opponentOf(new User('carla')));
    }

    public function testTheDuelStartsWhenBothAreFreshlyReady(): void
    {
        $challenge = $this->challenge('2026-11-20 14:00')->accept(new \DateTimeImmutable('2026-11-20 15:00'));
        $challenge->markReady($this->alice, new \DateTimeImmutable('2026-11-20 15:10:00'));

        self::assertFalse($challenge->areBothReady(new \DateTimeImmutable('2026-11-20 15:10:05')));

        $challenge->markReady($this->bruno, new \DateTimeImmutable('2026-11-20 15:10:30'));
        self::assertTrue($challenge->areBothReady(new \DateTimeImmutable('2026-11-20 15:10:40')));
        self::assertFalse($challenge->areBothReady(new \DateTimeImmutable('2026-11-20 15:10:50')), 'Alice left: her beat is 50 s old');
        self::assertFalse($challenge->isReady(new User('carla'), new \DateTimeImmutable('2026-11-20 15:10:40')));
    }

    public function testAQuestionsClockIsSetOnce(): void
    {
        $duel = new QuizTournamentDuel($this->tournament(), $this->alice, $this->bruno, QuizTournamentDuelOrigin::Challenge, 7, new \DateTimeImmutable('2026-11-20 15:11'), 1000, 1000, false, false);
        $question = new QuizTournamentDuelQuestion($duel, 0, QuestionType::QcmMulti, 30);
        $question->serve(new \DateTimeImmutable('2026-11-20 15:11:00'), new \DateTimeImmutable('2026-11-20 15:11:30'));
        $question->serve(new \DateTimeImmutable('2026-11-20 15:11:20'), new \DateTimeImmutable('2026-11-20 15:11:50'));

        self::assertEquals(new \DateTimeImmutable('2026-11-20 15:11:00'), $question->getServedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 15:11:30'), $question->getDeadlineAt());
        self::assertSame([$question], $duel->getQuestions()->toArray());
    }

    public function testAMultipleChoiceQuestionGetsItsOwnTime(): void
    {
        $tournament = $this->tournament();

        self::assertSame(40, $tournament->secondsFor(QuestionType::QcmMulti));
        self::assertSame(30, $tournament->secondsFor(QuestionType::VraiFaux));
    }

    public function testExtraTimeIsEachPlayersOwn(): void
    {
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->alice, 1);
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->bruno, 2);
        $duel = new QuizTournamentDuel($this->tournament(), $this->alice, $this->bruno, QuizTournamentDuelOrigin::Challenge, 7, new \DateTimeImmutable('2026-11-20 15:11'), 1000, 1000, false, false);
        $question = new QuizTournamentDuelQuestion($duel, 0, QuestionType::Qcm, 30);
        self::assertSame([1 => 30, 2 => 30], $duel->timeLimits($question));

        $duel->setExtraTimePercents(null, '33.33');
        self::assertSame([1 => 30, 2 => 40], $duel->timeLimits($question), 'a third more for Bruno alone, rounded up');
    }

    public function testARoundIsRestrictedToAnOptionOrAGroupNeverBoth(): void
    {
        $program = $this->createStub(Program::class);
        $round = new QuizTournamentRound($this->tournament(), $program, $this->alice, new \DateTimeImmutable());
        $batch = new GroupBatch($program, $this->alice, 'TP', [[1, 2], [3, 4, 5]]);

        $round->restrictToGroup($batch, 1);
        self::assertSame([3, 4, 5], $round->restrictedGroupMemberIds());

        $round->restrictToOption(new Option('SISR', 'SISR', '#336699'));
        self::assertNull($round->getRestrictedGroupBatch());
        self::assertNull($round->restrictedGroupMemberIds());
        self::assertSame(QuizTournamentRoundStatus::Open, $round->getStatus());

        $round->markPaired(new \DateTimeImmutable(), null);
        self::assertSame(QuizTournamentRoundStatus::Paired, $round->getStatus());
    }

    private function challenge(string $sentAt): QuizTournamentChallenge
    {
        return new QuizTournamentChallenge($this->tournament(), $this->alice, $this->bruno, false, new \DateTimeImmutable($sentAt));
    }

    private function tournament(): QuizTournament
    {
        return new QuizTournament('Tournoi', new \DateTimeImmutable('2026-11-10'), new \DateTimeImmutable('2027-02-10'), QuizTournamentPalette::Lavender, new User('admin'));
    }
}
