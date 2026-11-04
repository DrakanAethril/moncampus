<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuizTournamentDuelPhase;
use App\Service\QuizTournament\QuizTournamentDuelClock;
use PHPUnit\Framework\TestCase;

/**
 * The duel's clock (design/validated/quiz-tournament.md §5.2), on primitives: when each question is
 * served, when it stops taking answers, when the next one comes, and when the duel is over - all
 * from the duel's start, the questions' time limits, what is already stamped and when each player
 * answered. Nobody's presence moves it: leaving pauses nothing.
 */
class QuizTournamentDuelClockTest extends TestCase
{
    private const string START = '2026-11-10 10:00:00';

    public function testTheFirstQuestionIsServedAfterTheLead(): void
    {
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [$this->question(20), $this->question(20)]);

        self::assertEquals($this->at(QuizTournamentDuelClock::LEAD_SECONDS), $timeline->moments[0]->servedAt);
        self::assertEquals($this->at(QuizTournamentDuelClock::LEAD_SECONDS + 20), $timeline->moments[0]->deadlineAt);
        self::assertSame(QuizTournamentDuelPhase::Waiting, $timeline->phaseAt($this->at(2)));
        self::assertNull($timeline->rankAt($this->at(2)));
        self::assertEquals($this->at(QuizTournamentDuelClock::LEAD_SECONDS), $timeline->nextChangeAt($this->at(2)));
    }

    public function testWithoutAnswersEachQuestionRunsToItsDeadlineThenTheTransition(): void
    {
        $lead = QuizTournamentDuelClock::LEAD_SECONDS;
        $gap = QuizTournamentDuelClock::TRANSITION_SECONDS;
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [$this->question(20), $this->question(30), $this->question(20)]);

        self::assertEquals($this->at($lead + 20 + $gap), $timeline->moments[1]->servedAt);
        self::assertEquals($this->at($lead + 20 + $gap + 30), $timeline->moments[1]->deadlineAt);
        self::assertEquals($this->at($lead + 20 + $gap + 30 + $gap), $timeline->moments[2]->servedAt);

        self::assertSame(QuizTournamentDuelPhase::Question, $timeline->phaseAt($this->at($lead + 10)));
        self::assertSame(0, $timeline->rankAt($this->at($lead + 10)));
        self::assertEquals($this->at($lead + 20), $timeline->nextChangeAt($this->at($lead + 10)));

        self::assertSame(QuizTournamentDuelPhase::Reveal, $timeline->phaseAt($this->at($lead + 21)));
        self::assertSame(0, $timeline->rankAt($this->at($lead + 21)));
        self::assertEquals($this->at($lead + 20 + $gap), $timeline->nextChangeAt($this->at($lead + 21)));

        self::assertSame(1, $timeline->rankAt($this->at($lead + 20 + $gap)));
    }

    public function testBothAnswersCloseTheQuestionEarly(): void
    {
        $lead = QuizTournamentDuelClock::LEAD_SECONDS;
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [
            $this->question(20, null, [$this->at($lead + 3), $this->at($lead + 7)]),
            $this->question(20),
        ]);

        self::assertEquals($this->at($lead + 7), $timeline->moments[0]->closesAt);
        self::assertEquals($this->at($lead + 7 + QuizTournamentDuelClock::TRANSITION_SECONDS), $timeline->moments[1]->servedAt);
        self::assertSame(QuizTournamentDuelPhase::Reveal, $timeline->phaseAt($this->at($lead + 8)));
    }

    public function testOneAnswerDoesNotCloseTheQuestion(): void
    {
        $lead = QuizTournamentDuelClock::LEAD_SECONDS;
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [$this->question(20, null, [$this->at($lead + 3)]), $this->question(20)]);

        self::assertEquals($this->at($lead + 20), $timeline->moments[0]->closesAt);
        self::assertSame(QuizTournamentDuelPhase::Question, $timeline->phaseAt($this->at($lead + 10)));
    }

    public function testAStampedServeIsNeverMoved(): void
    {
        // Question 1 was stamped late (a request after the deadline of question 0 computed it from a
        // state an answer then changed): the stamp holds, and everything after follows it.
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [
            $this->question(20, $this->at(5)),
            $this->question(20, $this->at(40)),
            $this->question(20),
        ]);

        self::assertEquals($this->at(40), $timeline->moments[1]->servedAt);
        self::assertEquals($this->at(60), $timeline->moments[1]->deadlineAt);
        self::assertEquals($this->at(62), $timeline->moments[2]->servedAt);
        self::assertTrue($timeline->moments[1]->stamped);
        self::assertFalse($timeline->moments[2]->stamped);
    }

    public function testDueToServeNamesEveryQuestionWhoseTimeHasComeAndIsNotStamped(): void
    {
        $lead = QuizTournamentDuelClock::LEAD_SECONDS;
        $gap = QuizTournamentDuelClock::TRANSITION_SECONDS;
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [
            $this->question(20, $this->at($lead)),
            $this->question(20),
            $this->question(20),
        ]);

        self::assertSame([], $timeline->dueToServe($this->at($lead + 1)));

        // Nobody asked for a minute: both questions that came in the meantime are stamped at the
        // time they came, not at the time somebody asked.
        $due = $timeline->dueToServe($this->at(300));
        self::assertEquals([1 => $this->at($lead + 20 + $gap), 2 => $this->at($lead + 40 + 2 * $gap)], $due);
    }

    public function testTheDuelEndsAfterTheLastTransition(): void
    {
        $lead = QuizTournamentDuelClock::LEAD_SECONDS;
        $gap = QuizTournamentDuelClock::TRANSITION_SECONDS;
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [$this->question(20), $this->question(20)]);

        $end = $this->at($lead + 20 + $gap + 20 + $gap);
        self::assertEquals($end, $timeline->endsAt);
        self::assertFalse($timeline->isOver($this->at($lead + 20 + $gap + 21)));
        self::assertSame(QuizTournamentDuelPhase::Reveal, $timeline->phaseAt($this->at($lead + 20 + $gap + 21)));
        self::assertEquals($end, $timeline->nextChangeAt($this->at($lead + 20 + $gap + 21)));
        self::assertTrue($timeline->isOver($end));
        self::assertSame(QuizTournamentDuelPhase::Finished, $timeline->phaseAt($end));
        self::assertNull($timeline->rankAt($end));
        self::assertNull($timeline->nextChangeAt($end));
    }

    public function testADuelWithoutQuestionsIsOverAtOnce(): void
    {
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), []);

        self::assertTrue($timeline->isOver($this->at(0)));
        self::assertSame(QuizTournamentDuelPhase::Finished, $timeline->phaseAt($this->at(0)));
        self::assertEquals($this->at(0), $timeline->endsAt);
        self::assertSame([], $timeline->dueToServe($this->at(10)));
    }

    public function testAnAnswerIsTakenOnlyBetweenServeAndDeadline(): void
    {
        $lead = QuizTournamentDuelClock::LEAD_SECONDS;
        $timeline = QuizTournamentDuelClock::timeline($this->at(0), [$this->question(20), $this->question(20)]);

        self::assertFalse($timeline->acceptsAnswer(0, $this->at($lead - 1)));
        self::assertTrue($timeline->acceptsAnswer(0, $this->at($lead)));
        self::assertTrue($timeline->acceptsAnswer(0, $this->at($lead + 20)));
        self::assertFalse($timeline->acceptsAnswer(0, $this->at($lead + 21)));
        self::assertFalse($timeline->acceptsAnswer(1, $this->at($lead + 10)), 'never the next question before it is served');
        self::assertFalse($timeline->acceptsAnswer(7, $this->at($lead + 10)));
    }

    public function testTheClockReadsWholeSeconds(): void
    {
        self::assertEquals(
            new \DateTimeImmutable(self::START),
            QuizTournamentDuelClock::second(new \DateTimeImmutable(self::START.'.734512')),
        );
    }

    private function at(int $seconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::START))->modify(\sprintf('+%d seconds', $seconds));
    }

    /**
     * @param list<\DateTimeImmutable> $answeredAt
     *
     * @return array{seconds: int, servedAt: \DateTimeImmutable|null, answeredAt: list<\DateTimeImmutable>}
     */
    private function question(int $seconds, ?\DateTimeImmutable $servedAt = null, array $answeredAt = []): array
    {
        return ['seconds' => $seconds, 'servedAt' => $servedAt, 'answeredAt' => $answeredAt];
    }
}
