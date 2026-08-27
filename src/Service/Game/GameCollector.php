<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns what already happened in the application into ledger lines - and does it by **re-reading
 * the sources**, not by listening for an event.
 *
 * The choice is deliberate and it is what makes the whole family of automatic rules safe. A hook on
 * every deposit, attempt, revision and application would be a dozen listeners in a dozen features,
 * each of which pays exactly once and silently pays nothing when it is bypassed - and a game whose
 * points depend on which code path saved a row is a game nobody can audit. Here the sources are the
 * truth, the ledger is a projection of them, and App\Service\Game\GameLedger refuses a second line
 * on the same (sourceType, sourceId, ruleCode) - so running this ten times is the same as running
 * it once.
 *
 * What it never reads is just as much a decision (§4, decision 4): no AssignmentView, no
 * AudioListenProgress, no VideoWatchProgress, no login, no dashboard visit. A trace says a page was
 * opened, not that it was read, and it is trivial to produce in bulk.
 */
final class GameCollector
{
    /** How many characters a wiki revision must add or remove to be « substantielle ». */
    private const int WIKI_SUBSTANTIAL_DELTA = 200;

    public function __construct(
        private readonly GameLedger $ledger,
        private readonly GameWorkReader $work,
        private readonly GameSignalReader $signals,
        private readonly GameAttendanceProjector $attendance,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Bring one student's period up to date, and flush.
     *
     * Cheap to call: everything it would write again is refused by the ledger, so the screens can
     * ask for it before drawing and the closure can ask for it again before freezing.
     */
    public function collect(User $student, Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): void
    {
        $this->collectWithoutFlush($student, $program, $period, $now);
        $this->entityManager->flush();
    }

    /** The same, for a caller running over a whole class and flushing once. */
    public function collectWithoutFlush(User $student, Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        $start = $period->getStartDate();
        $end = $period->getEndDate();

        if (null === $start || null === $end) {
            return;
        }

        // Never collect beyond today: a period runs until June and its deadlines are not all in the
        // past, so the window closes on whichever comes first.
        $to = min($end, $now);

        // The relevé's default answer - « net » - is a complete answer that nobody clicks, so it
        // cannot be paid by an edit. It is projected here instead, where every reading of the
        // period passes: a statement opened and left alone still pays the whole class.
        $this->attendance->project($student, $program);

        $this->collectWork($student, $program, $period, $now);
        $this->collectEngagement($student, $program, $period, $start, $to);
    }

    private function collectWork(User $student, Program $program, EvaluationPeriod $period, \DateTimeImmutable $now): void
    {
        foreach ($this->work->deadlines($student, $program, $period, $now) as $deadline) {
            if (!$deadline->isHonoured() || null === $deadline->ruleCode) {
                continue;
            }

            $this->ledger->record(
                $student,
                $program,
                $deadline->ruleCode,
                $deadline->sourceType,
                $deadline->sourceId,
                $deadline->occurredAt,
            );
        }
    }

    private function collectEngagement(User $student, Program $program, EvaluationPeriod $period, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $this->collectQuizProgress($student, $program, $period, $from, $to);

        foreach ($this->signals->optionalSurveyAnswers($student, $from, $to) as $row) {
            $this->ledger->record($student, $program, GameRuleCatalog::ENGAGEMENT_SURVEY, 'SurveyTarget', $row['id'], $row['at']);
        }

        foreach ($this->signals->attendedSignups($student, $from, $to) as $row) {
            $this->ledger->record($student, $program, GameRuleCatalog::ENGAGEMENT_SIGNUP_ATTENDED, 'SignupListRegistration', $row['id'], $row['at']);
        }

        foreach ($this->signals->wikiRevisions($student, $from, $to) as $row) {
            // A typo fix is a real gesture and is not a contribution. The threshold is what the
            // design calls « révisée substantiellement », and the weekly cap of two is what stops
            // the rest from being emitted in a batch on a Sunday evening.
            if ($row['length'] < self::WIKI_SUBSTANTIAL_DELTA) {
                continue;
            }

            $this->ledger->record($student, $program, GameRuleCatalog::ENGAGEMENT_WIKI, 'WikiRevision', $row['id'], $row['createdAt']);
        }

        foreach ($this->signals->applications($student, $from, $to) as $row) {
            $this->ledger->record($student, $program, GameRuleCatalog::ENGAGEMENT_APPLICATION, $row['type'], $row['id'], $row['at']);
        }

        foreach ($this->signals->sharedResources($student, $program, $from, $to) as $row) {
            $this->ledger->record($student, $program, GameRuleCatalog::ENGAGEMENT_SHARED_RESOURCE, 'SharedDocument', $row['id'], $row['at']);
        }
    }

    /**
     * A training quiz retaken **and improved** (§5.3).
     *
     * Retaking pays nothing on its own - that would pay clicking « recommencer » - and the score is
     * still not what is being paid: what is paid is that this attempt beat every earlier one on the
     * same quiz. Somebody at 100 % can no longer earn from it, which is the correct outcome for a
     * rule whose subject is progress.
     */
    private function collectQuizProgress(User $student, Program $program, EvaluationPeriod $period, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $best = $this->signals->bestScoresBefore($student, $from);

        foreach ($this->signals->quizAttempts($student, $from, $to) as $attempt) {
            if ('entrainement' !== $attempt['mode']) {
                continue;
            }

            $percent = $this->signals->percent($attempt['correct'], $attempt['total']);

            if (null === $percent) {
                continue;
            }

            $instance = $attempt['instance'];
            $previous = $best[$instance] ?? null;
            $best[$instance] = max($previous ?? 0.0, $percent);

            // The first sitting of a quiz has nothing to beat, and is not a « refait ».
            if (null === $previous || $percent <= $previous) {
                continue;
            }

            $this->ledger->record($student, $program, GameRuleCatalog::ENGAGEMENT_QUIZ_PROGRESS, 'QuizAttempt', $attempt['id'], $attempt['submittedAt']);
        }
    }
}
