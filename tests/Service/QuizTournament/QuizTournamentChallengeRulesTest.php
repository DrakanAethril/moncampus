<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuizTournamentChallengeRefusal;
use App\Service\QuizTournament\QuizTournamentChallengeFacts;
use App\Service\QuizTournament\QuizTournamentChallengeRules;
use PHPUnit\Framework\TestCase;

/**
 * « A défie B » (design/validated/quiz-tournament.md §4.1), on primitives: every reason a challenge
 * is refused, each one named so the button can say why it is greyed out.
 */
class QuizTournamentChallengeRulesTest extends TestCase
{
    public function testAChallengeBetweenTwoFreeEligiblePlayersIsAllowed(): void
    {
        $now = $this->paris('2026-11-20 14:00');

        self::assertSame([], QuizTournamentChallengeRules::refusals($this->facts(), $now));
        self::assertTrue(QuizTournamentChallengeRules::canChallenge($this->facts(), $now));
    }

    public function testTheTournamentMustBeRunning(): void
    {
        self::assertSame(
            [QuizTournamentChallengeRefusal::NotInProgress],
            QuizTournamentChallengeRules::refusals($this->facts(inProgress: false), $this->paris('2026-11-20 14:00')),
        );
    }

    public function testBothPlayersMustBeEligibleAndDifferent(): void
    {
        $now = $this->paris('2026-11-20 14:00');

        self::assertSame([QuizTournamentChallengeRefusal::ChallengerNotEligible], QuizTournamentChallengeRules::refusals($this->facts(challengerEligible: false), $now));
        self::assertSame([QuizTournamentChallengeRefusal::ChallengedNotEligible], QuizTournamentChallengeRules::refusals($this->facts(challengedEligible: false), $now));
        self::assertSame([QuizTournamentChallengeRefusal::SamePlayer], QuizTournamentChallengeRules::refusals($this->facts(samePlayer: true), $now));
    }

    public function testTheChallengerHasNoOtherOutgoingChallengePending(): void
    {
        self::assertSame(
            [QuizTournamentChallengeRefusal::OutgoingPending],
            QuizTournamentChallengeRules::refusals($this->facts(outgoing: 1), $this->paris('2026-11-20 14:00')),
        );
    }

    public function testTheChallengedHasFewerThanFivePendingChallenges(): void
    {
        $now = $this->paris('2026-11-20 14:00');

        self::assertSame([], QuizTournamentChallengeRules::refusals($this->facts(incoming: 4), $now));
        self::assertSame([QuizTournamentChallengeRefusal::TooManyIncoming], QuizTournamentChallengeRules::refusals($this->facts(incoming: 5), $now));
    }

    public function testARefusalBlocksTheSameChallengerFor48Hours(): void
    {
        $now = $this->paris('2026-11-20 14:00');

        self::assertSame(
            [QuizTournamentChallengeRefusal::RecentlyRefused],
            QuizTournamentChallengeRules::refusals($this->facts(refusedAt: $this->paris('2026-11-18 14:01')), $now),
        );
        self::assertSame([], QuizTournamentChallengeRules::refusals($this->facts(refusedAt: $this->paris('2026-11-18 14:00')), $now));
    }

    public function testThreeEncountersOfThePairTodayCloseIt(): void
    {
        $now = $this->paris('2026-11-20 22:00');
        $two = [$this->paris('2026-11-20 08:00'), $this->paris('2026-11-20 12:00')];
        $three = [...$two, $this->paris('2026-11-20 21:00')];

        self::assertSame([], QuizTournamentChallengeRules::refusals($this->facts(encounters: $two), $now));
        self::assertSame([QuizTournamentChallengeRefusal::DailyPairCap], QuizTournamentChallengeRules::refusals($this->facts(encounters: $three), $now));
    }

    public function testTheDayIsTheParisCivilDay(): void
    {
        // 23:30 UTC on the 19th is 00:30 on the 20th in Paris: it counts for the 20th.
        $encounters = [
            new \DateTimeImmutable('2026-11-19 23:30', new \DateTimeZone('UTC')),
            $this->paris('2026-11-20 09:00'),
            $this->paris('2026-11-20 10:00'),
            $this->paris('2026-11-19 23:59'),
        ];

        self::assertSame(3, QuizTournamentChallengeRules::encountersToday($encounters, $this->paris('2026-11-20 11:00')));
        self::assertSame(1, QuizTournamentChallengeRules::encountersToday($encounters, $this->paris('2026-11-19 23:59:30')));
        self::assertTrue(QuizTournamentChallengeRules::isAtDailyCap($encounters, $this->paris('2026-11-20 11:00')));
        self::assertFalse(QuizTournamentChallengeRules::isAtDailyCap($encounters, $this->paris('2026-11-21 00:00')));
    }

    public function testTheDayBoundsAreParisMidnights(): void
    {
        [$from, $to] = QuizTournamentChallengeRules::dayBounds(new \DateTimeImmutable('2026-11-19 23:30', new \DateTimeZone('UTC')));

        self::assertSame('2026-11-20 00:00:00 Europe/Paris', $from->format('Y-m-d H:i:s e'));
        self::assertSame('2026-11-21 00:00:00 Europe/Paris', $to->format('Y-m-d H:i:s e'));
    }

    public function testEveryReasonIsListedInTheOrderItIsExplained(): void
    {
        $refusals = QuizTournamentChallengeRules::refusals($this->facts(
            outgoing: 1,
            incoming: 5,
            refusedAt: $this->paris('2026-11-20 13:00'),
            encounters: array_fill(0, 3, $this->paris('2026-11-20 09:00')),
        ), $this->paris('2026-11-20 14:00'));

        self::assertSame([
            QuizTournamentChallengeRefusal::OutgoingPending,
            QuizTournamentChallengeRefusal::TooManyIncoming,
            QuizTournamentChallengeRefusal::RecentlyRefused,
            QuizTournamentChallengeRefusal::DailyPairCap,
        ], $refusals);
        self::assertSame(QuizTournamentChallengeRefusal::OutgoingPending, QuizTournamentChallengeRules::firstRefusal($this->facts(outgoing: 1), $this->paris('2026-11-20 14:00')));
        self::assertNull(QuizTournamentChallengeRules::firstRefusal($this->facts(), $this->paris('2026-11-20 14:00')));
    }

    public function testEveryRefusalHasItsOwnSentence(): void
    {
        $keys = array_map(static fn (QuizTournamentChallengeRefusal $refusal): string => $refusal->labelKey(), QuizTournamentChallengeRefusal::cases());

        self::assertCount(\count($keys), array_unique($keys));
    }

    public function testAChallengeLasts24HoursAndSoDoesTheRoom(): void
    {
        $at = $this->paris('2026-11-20 14:00');

        self::assertEquals($this->paris('2026-11-21 14:00'), QuizTournamentChallengeRules::expiresAt($at));
        self::assertEquals($this->paris('2026-11-21 14:00'), QuizTournamentChallengeRules::roomClosesAt($at));
    }

    public function testReadyHoldsWhileTheHeartbeatIsFresh(): void
    {
        $now = $this->paris('2026-11-20 14:00:45');

        self::assertTrue(QuizTournamentChallengeRules::isStillReady($this->paris('2026-11-20 14:00:00'), $now));
        self::assertFalse(QuizTournamentChallengeRules::isStillReady($this->paris('2026-11-20 13:59:59'), $now));
        self::assertFalse(QuizTournamentChallengeRules::isStillReady(null, $now));
    }

    /** @param list<\DateTimeImmutable> $encounters */
    private function facts(
        bool $inProgress = true,
        bool $challengerEligible = true,
        bool $challengedEligible = true,
        bool $samePlayer = false,
        int $outgoing = 0,
        int $incoming = 0,
        ?\DateTimeImmutable $refusedAt = null,
        array $encounters = [],
    ): QuizTournamentChallengeFacts {
        return new QuizTournamentChallengeFacts($inProgress, $challengerEligible, $challengedEligible, $samePlayer, $outgoing, $incoming, $refusedAt, $encounters);
    }

    private function paris(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('Europe/Paris'));
    }
}
