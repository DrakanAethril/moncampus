<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptAnswer;
use App\Entity\QuizAttemptEvent;
use App\Enum\QuestionDifficulty;
use App\Enum\QuizAttemptEventType;
use App\Repository\QuizAttemptEventRepository;

/**
 * The one place rows become the primitives App\Service\QuizSupervisionAssessor reads - and, past the
 * verdicts, the rows the timeline draws.
 *
 * Split from the assessor on purpose: the rule is a function over durations and booleans and is
 * tested as one, while everything about *where those durations live* belongs here. The rule is
 * therefore never copied into a controller or a template - they call this, and this calls it.
 *
 * @phpstan-type TimelineAbsence array{durationMs: int, offsetPercent: float, widthPercent: float}
 * @phpstan-type TimelineRow array{
 *     position: int,
 *     number: int,
 *     label: string,
 *     difficulty: string,
 *     elapsedMs: int|null,
 *     barPercent: float,
 *     displayCount: int,
 *     isCorrect: bool|null,
 *     flagged: bool,
 *     reasons: list<string>,
 *     absences: list<TimelineAbsence>,
 *     atMs: int|null,
 * }
 */
class QuizSupervisionReportBuilder
{
    public function __construct(
        private readonly QuizAttemptEventRepository $events,
        private readonly QuizSupervisionAssessor $assessor,
    ) {
    }

    public function build(QuizAttempt $attempt): QuizSupervisionReport
    {
        return $this->assessor->assess(
            $this->questionsOf($attempt),
            $attempt->getQuizInstance()->getSupervisionExitSeconds(),
        );
    }

    /**
     * One row per question, with everything the timeline needs to be drawn - the bar is the display
     * time, the notches are the exits, both as percentages of the attempt's longest question so the
     * template does no arithmetic of its own.
     *
     * @return list<TimelineRow>
     */
    public function timelineRows(QuizAttempt $attempt, QuizSupervisionReport $report): array
    {
        $eventsByPosition = $this->eventsByPosition($attempt);
        $answers = array_values($attempt->getAttemptAnswers()->toArray());
        $longestMs = max(1, ...array_map(static fn (QuizAttemptAnswer $a): int => $a->getElapsedMs() ?? 0, $answers));
        $startedAt = (float) $attempt->getStartedAt()->format('U.u');

        $rows = [];
        foreach ($answers as $index => $answer) {
            $verdict = $report->verdictAt($index);
            $elapsedMs = $answer->getElapsedMs();
            $servedAt = $answer->getServedAt();

            $absences = [];
            foreach ($eventsByPosition[$index] ?? [] as $event) {
                $duration = $event->getDurationMs();
                if (!$event->getType()->opensAbsence() || null === $duration) {
                    continue;
                }
                // Where inside the question's own window the absence sits. Without a display time
                // there is nothing to place it against, so it is drawn from the start of the bar.
                $offsetMs = null !== $servedAt && null !== $elapsedMs && $elapsedMs > 0
                    ? max(0, (int) round(($event->preciseTimestamp() - (float) $servedAt->format('U.u')) * 1000))
                    : 0;

                $absences[] = [
                    'durationMs' => $duration,
                    'offsetPercent' => null !== $elapsedMs && $elapsedMs > 0 ? min(100.0, round($offsetMs / $elapsedMs * 100, 1)) : 0.0,
                    'widthPercent' => null !== $elapsedMs && $elapsedMs > 0 ? min(100.0, max(1.0, round($duration / $elapsedMs * 100, 1))) : 100.0,
                ];
            }

            $question = $answer->getInstanceQuestion();
            $rows[] = [
                'position' => $index,
                'number' => $index + 1,
                'label' => (string) $question->getLabel(),
                'difficulty' => $question->getEffectiveDifficulty()->value,
                'elapsedMs' => $elapsedMs,
                'barPercent' => round(($elapsedMs ?? 0) / $longestMs * 100, 1),
                'displayCount' => $answer->getDisplayCount(),
                'isCorrect' => $answer->getIsCorrect(),
                'flagged' => $verdict->flagged,
                'reasons' => $verdict->reasons,
                'absences' => $absences,
                // How far into the attempt this question was served - « à 4′12 », the phrase the
                // whole design exists to be able to say out loud.
                'atMs' => null !== $servedAt ? max(0, (int) round(((float) $servedAt->format('U.u') - $startedAt) * 1000)) : null,
            ];
        }

        return $rows;
    }

    /** @return list<QuizSupervisionQuestion> */
    private function questionsOf(QuizAttempt $attempt): array
    {
        $eventsByPosition = $this->eventsByPosition($attempt);

        $questions = [];
        foreach (array_values($attempt->getAttemptAnswers()->toArray()) as $index => $answer) {
            $absences = [];
            $hasPaste = false;
            foreach ($eventsByPosition[$index] ?? [] as $event) {
                $duration = $event->getDurationMs();
                if ($event->getType()->opensAbsence() && null !== $duration) {
                    $absences[] = $duration;
                }
                if (QuizAttemptEventType::Paste === $event->getType()) {
                    $hasPaste = true;
                }
            }

            $questions[] = new QuizSupervisionQuestion(
                $index,
                $answer->getElapsedMs(),
                $answer->getDisplayCount(),
                $answer->getIsCorrect(),
                QuestionDifficulty::Difficile === $answer->getInstanceQuestion()->getEffectiveDifficulty(),
                $absences,
                $hasPaste,
            );
        }

        return $questions;
    }

    /** @return array<int, list<QuizAttemptEvent>> */
    private function eventsByPosition(QuizAttempt $attempt): array
    {
        $byPosition = [];
        foreach ($this->events->findForAttempt($attempt) as $event) {
            $position = $event->getPosition();
            if (null !== $position) {
                $byPosition[$position][] = $event;
            }
        }

        return $byPosition;
    }
}
