<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * The duel's clock (design/validated/quiz-tournament.md §5.2), as a pure function: from the duel's
 * start, each question's time limit, the serve already stamped on it and when each player answered,
 * when every question is served, closes and gives way to the next.
 *
 * - The first question is served LEAD_SECONDS after the duel is created - the room's « C'est parti
 *   dans 3 » and the page that follows. Both players get it at that same moment, whoever loads first.
 * - A question closes at its deadline (servedAt + its time limit), or as soon as **both** players
 *   answered; the next one is served TRANSITION_SECONDS later - the « JUSTE ! » / « RATÉ » of both
 *   and the score.
 * - The duel is over TRANSITION_SECONDS after its last question closed; a duel without questions is
 *   over when it starts.
 *
 * **Nothing here depends on who is looking.** The clock runs on the server's time whether the
 * players are there or not: leaving pauses nothing, a question nobody answered closes at its
 * deadline, and a duel both players left runs out by itself (app:quiz-tournament:close-duels then
 * finishes it). A serve, once stamped on the question, is never moved - what follows is computed from
 * it - so a reload never restarts a clock; the stamps are written by whichever request first sees
 * the moment pass, at the moment computed here, not at the time of the request.
 *
 * Times are whole seconds: that is what the database keeps (second()).
 */
final class QuizTournamentDuelClock
{
    public const int LEAD_SECONDS = 5;
    public const int TRANSITION_SECONDS = 2;

    /**
     * @param list<array{seconds: int, servedAt: \DateTimeImmutable|null, answeredAt: list<\DateTimeImmutable>}> $questions in rank order;
     *                                                                                                             `answeredAt` holds when each player who answered did
     */
    public static function timeline(\DateTimeImmutable $startedAt, array $questions): QuizTournamentDuelTimeline
    {
        $startedAt = self::second($startedAt);
        $moments = [];
        $next = $startedAt->modify(\sprintf('+%d seconds', self::LEAD_SECONDS));

        foreach ($questions as $rank => $question) {
            $stamped = null !== $question['servedAt'];
            $servedAt = $stamped ? self::second($question['servedAt']) : $next;
            $deadlineAt = $servedAt->modify(\sprintf('+%d seconds', $question['seconds']));

            $closesAt = $deadlineAt;
            if (\count($question['answeredAt']) >= 2) {
                $last = max(array_map(self::second(...), $question['answeredAt']));
                $closesAt = min($last, $deadlineAt);
            }

            $moments[] = new QuizTournamentDuelMoment($rank, $servedAt, $deadlineAt, $closesAt, $stamped);
            $next = $closesAt->modify(\sprintf('+%d seconds', self::TRANSITION_SECONDS));
        }

        return new QuizTournamentDuelTimeline($moments, [] === $moments ? $startedAt : $next);
    }

    /** The moment cut to the whole second - what a DATETIME column keeps. */
    public static function second(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTime((int) $at->format('G'), (int) $at->format('i'), (int) $at->format('s'));
    }
}
