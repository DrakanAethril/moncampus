<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizSupervisionAssessor;
use App\Service\QuizSupervisionQuestion;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides what a teacher is shown - written before the service, because it is a pure
 * function over durations and booleans and that is the exact case where a test comes first.
 *
 * One test per line of the design's "cas par cas" table, and the one that matters most is the
 * negative: four exits of under three seconds signal nothing. A device of this kind is judged on
 * what it does *not* raise - remove any of the three conditions and it starts pointing at the
 * honest student who was stuck.
 */
class QuizSupervisionAssessorTest extends TestCase
{
    private const int THRESHOLD_SECONDS = 8;

    private QuizSupervisionAssessor $assessor;

    protected function setUp(): void
    {
        $this->assessor = new QuizSupervisionAssessor();
    }

    /** Q7 displayed 62 s, a 40 s absence in the middle, answer right - the case it exists to catch. */
    public function testTheThreeConditionsTogetherFlagTheQuestion(): void
    {
        $report = $this->assess([$this->question(7, elapsedMs: 62_000, isCorrect: true, absencesMs: [40_000])]);

        self::assertSame(1, $report->flaggedCount);
        self::assertTrue($report->verdictAt(7)->flagged);
    }

    /** The same absence, the same 62 s, but the answer is wrong: we do not report a student who failed. */
    public function testAWrongAnswerIsNeverFlagged(): void
    {
        $report = $this->assess([$this->question(7, elapsedMs: 62_000, isCorrect: false, absencesMs: [40_000])]);

        self::assertSame(0, $report->flaggedCount);
        self::assertFalse($report->verdictAt(7)->flagged);
        // The absence is still on the timeline - not flagged is not the same as not shown.
        self::assertNotSame([], $report->verdictAt(7)->absencesMs);
    }

    /** Answered in 3 s with two 300 ms flickers: nobody looks an answer up in three seconds. */
    public function testAFastAnswerWithFlickersIsNotFlagged(): void
    {
        $report = $this->assess([$this->question(2, elapsedMs: 3_000, isCorrect: true, absencesMs: [300, 300])]);

        self::assertSame(0, $report->flaggedCount);
    }

    /**
     * The negative that carries the whole device: four exits, none of them reaching three seconds,
     * spread over the assessment. Nothing is flagged - the rule's job is above all *not* to alert.
     */
    public function testShortExitsAcrossTheWholeAttemptFlagNothing(): void
    {
        $report = $this->assess([
            $this->question(1, elapsedMs: 30_000, isCorrect: true, absencesMs: [2_400]),
            $this->question(4, elapsedMs: 45_000, isCorrect: true, absencesMs: [1_100]),
            $this->question(9, elapsedMs: 52_000, isCorrect: true, absencesMs: [2_900]),
            $this->question(15, elapsedMs: 38_000, isCorrect: false, absencesMs: [800]),
        ]);

        self::assertSame(0, $report->flaggedCount);
        self::assertSame(4, $report->absenceCount);
    }

    /** Q12 served five times for four cumulative minutes: reloading used to be how one got a fresh timer. */
    public function testAQuestionServedAgainAndAgainIsFlagged(): void
    {
        $report = $this->assess([$this->question(12, elapsedMs: 240_000, displayCount: 5, isCorrect: true)]);

        self::assertTrue($report->verdictAt(12)->flagged);
        self::assertContains(QuizSupervisionAssessor::REASON_REDISPLAYED, $report->verdictAt(12)->reasons);
    }

    /** A paste stands on its own: the text necessarily comes from somewhere else. */
    public function testAPasteFlagsOnItsOwn(): void
    {
        $report = $this->assess([$this->question(3, elapsedMs: 9_000, isCorrect: true, hasPaste: true)]);

        self::assertTrue($report->verdictAt(3)->flagged);
        self::assertContains(QuizSupervisionAssessor::REASON_PASTE, $report->verdictAt(3)->reasons);
    }

    /**
     * Everything right in under five seconds, the hard ones included. Not cheating in the moment -
     * a paper probably known in advance. Reported as such, at the scale of the class, and it never
     * enters the "à examiner" count.
     */
    public function testAnAttemptAnsweredTooFastIsSetApartRatherThanFlagged(): void
    {
        $report = $this->assess([
            $this->question(1, elapsedMs: 3_000, isCorrect: true),
            $this->question(2, elapsedMs: 4_200, isCorrect: true, isHard: true),
            $this->question(3, elapsedMs: 2_100, isCorrect: true, isHard: true),
        ]);

        self::assertTrue($report->suspiciouslyFast);
        self::assertSame(0, $report->flaggedCount);
    }

    /** The same speed with a wrong answer in it is somebody rushing, not somebody who knew. */
    public function testFastButImperfectIsNotSetApart(): void
    {
        $report = $this->assess([
            $this->question(1, elapsedMs: 3_000, isCorrect: true, isHard: true),
            $this->question(2, elapsedMs: 4_200, isCorrect: false),
        ]);

        self::assertFalse($report->suspiciouslyFast);
    }

    /** An absence of exactly the threshold counts; the threshold is a floor, not a bar to clear. */
    public function testAnAbsenceExactlyAtTheThresholdCounts(): void
    {
        $report = $this->assess([$this->question(5, elapsedMs: 30_000, isCorrect: true, absencesMs: [8_000])]);

        self::assertTrue($report->verdictAt(5)->flagged);
    }

    /** A long absence on a question barely displayed: there was no time to find anything. */
    public function testALongAbsenceOnABrieflyDisplayedQuestionIsNotFlagged(): void
    {
        $report = $this->assess([$this->question(5, elapsedMs: 12_000, isCorrect: true, absencesMs: [40_000])]);

        self::assertFalse($report->verdictAt(5)->flagged);
    }

    /** A question never reached carries no verdict of any kind. */
    public function testAnUnansweredQuestionIsNotFlagged(): void
    {
        $report = $this->assess([$this->question(20, elapsedMs: null, isCorrect: null, absencesMs: [40_000])]);

        self::assertFalse($report->verdictAt(20)->flagged);
        self::assertSame(0, $report->flaggedCount);
    }

    /** The threshold is the quiz's own, not a constant: raising it silences the shorter absences. */
    public function testTheExitThresholdIsPerQuiz(): void
    {
        $question = $this->question(7, elapsedMs: 62_000, isCorrect: true, absencesMs: [10_000]);

        self::assertTrue($this->assessor->assess([$question], 8)->verdictAt(7)->flagged);
        self::assertFalse($this->assessor->assess([$question], 15)->verdictAt(7)->flagged);
    }

    /** @param list<QuizSupervisionQuestion> $questions */
    private function assess(array $questions): \App\Service\QuizSupervisionReport
    {
        return $this->assessor->assess($questions, self::THRESHOLD_SECONDS);
    }

    /** @param list<int> $absencesMs */
    private function question(
        int $position,
        ?int $elapsedMs,
        ?bool $isCorrect,
        int $displayCount = 1,
        array $absencesMs = [],
        bool $hasPaste = false,
        bool $isHard = false,
    ): QuizSupervisionQuestion {
        return new QuizSupervisionQuestion($position, $elapsedMs, $displayCount, $isCorrect, $isHard, $absencesMs, $hasPaste);
    }
}
