<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\GameEntry;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameGestureObject;
use App\Repository\GameEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The teacher's gesture: the envelope, the two bounds, the contestation and the withdrawal
 * (§5.4 and §4, decision 6).
 *
 * Four limits, and each of them answers a way the gesture could go wrong:
 *
 * - **An envelope of six per teacher, per class, per period**, shown permanently and never
 *   recharged. It is what turns a gesture from a reflex into a decision, and it is what keeps the
 *   malus from becoming a register: a malus spends a token exactly like a bonus, so a malus posted
 *   is a bonus that can no longer be given.
 * - **A malus bears on dress or on behaviour and on nothing else.** The constraint is in the form
 *   and in the schema, not only in the documentation.
 * - **One teacher's net contribution is bounded at ±60.** A student is neither made nor unmade by
 *   a single teacher, and the bound is applied when the gesture is posted rather than at reading
 *   time - a student must be able to add up their own journal.
 * - **Cancelling writes an inverse line and gives the token back.** Nothing is ever deleted: a
 *   gesture that was posted and withdrawn stays readable by the person it was addressed to.
 */
final class TeacherGestureService
{
    /** How long a student may contest, in days (§5.4). */
    public const int CONTEST_DAYS = 7;

    /** The three values a gesture may carry, either way. */
    public const array VALUES = [5, 10, 20];

    public function __construct(
        private readonly GameEntryRepository $entries,
        private readonly GameLedger $ledger,
        private readonly GameSettingsProvider $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Tokens left in this teacher's envelope for this class and this period. */
    public function remaining(User $teacher, Program $program, EvaluationPeriod $period): int
    {
        $envelope = $this->settings->for($program)->getGestureEnvelope();

        return max(0, $envelope - \count($this->standingGestures($teacher, $program, $period)));
    }

    /**
     * Post a gesture, or refuse.
     *
     * @param int                     $points signed, one of ±5 / ±10 / ±20
     * @param GameGestureObject|null  $object mandatory on a malus, refused on a bonus
     *
     * @throws GestureRefused when the envelope is empty, the value is not offered, or the malus is malformed
     */
    public function post(
        User $teacher,
        User $student,
        Program $program,
        EvaluationPeriod $period,
        int $points,
        string $reason,
        ?GameGestureObject $object = null,
    ): GameEntry {
        $settings = $this->settings->for($program);
        $reason = trim($reason);

        if (!\in_array(abs($points), self::VALUES, true) || 0 === $points) {
            throw new GestureRefused('gameGestureValueRefusedMessage');
        }

        if ('' === $reason) {
            throw new GestureRefused('gameGestureReasonRequiredMessage');
        }

        $isMalus = $points < 0;

        if ($isMalus && !$settings->isMalusEnabled()) {
            throw new GestureRefused('gameGestureMalusDisabledMessage');
        }

        if ($isMalus && null === $object) {
            throw new GestureRefused('gameGestureObjectRequiredMessage');
        }

        if (!$isMalus && null !== $object) {
            // A bonus has no object: the free reason is the whole of it, and offering one would
            // quietly create a second kind of judgement.
            throw new GestureRefused('gameGestureObjectRefusedMessage');
        }

        if ($this->remaining($teacher, $program, $period) < 1) {
            throw new GestureRefused('gameGestureEnvelopeEmptyMessage');
        }

        // The ±60 bound, applied here rather than at reading time: what the journal shows is what
        // counted. A gesture that would cross the bound is truncated to what is left of it, and one
        // that would add nothing at all is refused rather than written as a zero.
        $bound = $settings->getGestureNetBound();
        $net = $this->netFor($teacher, $student, $program, $period);
        $effective = $points > 0 ? min($points, $bound - $net) : max($points, -$bound - $net);

        if (0 === $effective) {
            throw new GestureRefused('gameGestureBoundReachedMessage');
        }

        $entry = $this->ledger->record(
            $student,
            $program,
            $period,
            $isMalus ? GameRuleCatalog::RECOGNITION_GESTURE_MALUS : GameRuleCatalog::RECOGNITION_GESTURE_BONUS,
            null,
            null,
            null,
            $effective,
            $teacher,
            $reason,
        );

        if (null === $entry) {
            throw new GestureRefused('gameGestureValueRefusedMessage');
        }

        $entry->setGestureObject($object);
        $this->entityManager->flush();

        return $entry;
    }

    /**
     * Withdraw a gesture: an inverse line, and the token comes back.
     *
     * The original stays in the student's journal, marked by the line that undoes it - a gesture
     * somebody read cannot be made not to have existed.
     */
    public function cancel(GameEntry $entry, User $author): void
    {
        if ($this->isCancelled($entry)) {
            return;
        }

        $this->ledger->reverse($entry, $author, null);
        $this->entityManager->flush();
    }

    /** The student contests, within the seven days. */
    public function contest(GameEntry $entry, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        if (!$this->isContestable($entry, $now)) {
            return false;
        }

        $entry->setContestedAt($now);
        $this->entityManager->flush();

        return true;
    }

    /** The author answers and the gesture stands - withdrawing it is cancel(), not this. */
    public function resolve(GameEntry $entry): void
    {
        $entry->setResolvedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function isContestable(GameEntry $entry, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return null === $entry->getContestedAt()
            && !$this->isCancelled($entry)
            && $entry->getOccurredAt()->modify('+'.self::CONTEST_DAYS.' days') >= $now;
    }

    public function isCancelled(GameEntry $entry): bool
    {
        return null !== $this->entries->findReversalOf($entry);
    }

    /**
     * The gestures of one teacher on one class and period that still stand - what the envelope
     * counts, cancellations excluded.
     *
     * @return list<GameEntry>
     */
    public function standingGestures(User $teacher, Program $program, EvaluationPeriod $period): array
    {
        return array_values(array_filter(
            $this->entries->gesturesBy($teacher, $program, $period),
            fn (GameEntry $entry): bool => !$this->isCancelled($entry),
        ));
    }

    /** Every gesture of the class this teacher posted, cancelled ones included - the screen's list. */
    public function listFor(User $teacher, Program $program, EvaluationPeriod $period): array
    {
        return $this->entries->gesturesBy($teacher, $program, $period);
    }

    /** This teacher's net contribution to one student, cancellations already netted out. */
    public function netFor(User $teacher, User $student, Program $program, EvaluationPeriod $period): int
    {
        return $this->entries->gestureNet($teacher, $student, $program, $period);
    }
}
