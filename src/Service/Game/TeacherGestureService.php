<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameEntry;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameGestureObject;
use App\Repository\GameEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The teacher's gesture: its motive, its contestation and its withdrawal (§5.4 and §4, decision 6).
 *
 * **No envelope, and no per-teacher bound.** Both existed until 2026-08-28 - six tokens per teacher
 * per class per period, and a net contribution capped at ±60 - and both were removed on request. The
 * reason they went together is that they were the same idea twice: a quota placed between a teacher
 * and their own judgement. What remains is what actually governs the gesture, and it is not a
 * counter:
 *
 * - **A malus bears on dress or on behaviour and on nothing else.** The constraint is in the form
 *   and in the schema, not only in the documentation, and it is what keeps the one malus of the
 *   system from becoming a second disciplinary register.
 * - **A motive is mandatory**, read by the student exactly as it was typed, contestable for seven
 *   days.
 * - **Cancelling writes an inverse line.** Nothing is ever deleted: a gesture that was posted and
 *   withdrawn stays readable by the person it was addressed to.
 *
 * And no period either. A gesture is posted on the day it is deserved; which period it counts
 * towards is read afterwards from its date, and one posted on a day no period covers is still in
 * the student's journal.
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

    /**
     * Post a gesture, or refuse.
     *
     * @param int                    $points signed, one of ±5 / ±10 / ±20
     * @param GameGestureObject|null $object mandatory on a malus, refused on a bonus
     *
     * @throws GestureRefused when the value is not offered, the motive is missing, or the malus is malformed
     */
    public function post(
        User $teacher,
        User $student,
        Program $program,
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

        $entry = $this->ledger->record(
            $student,
            $program,
            $isMalus ? GameRuleCatalog::RECOGNITION_GESTURE_MALUS : GameRuleCatalog::RECOGNITION_GESTURE_BONUS,
            null,
            null,
            null,
            $points,
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
     * Withdraw a gesture: an inverse line.
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
    public function standingGestures(User $teacher, Program $program): array
    {
        return array_values(array_filter(
            $this->entries->gesturesBy($teacher, $program),
            fn (GameEntry $entry): bool => !$this->isCancelled($entry),
        ));
    }

    /** Every gesture of the class this teacher posted, cancelled ones included - the screen's list. */
    public function listFor(User $teacher, Program $program): array
    {
        return $this->entries->gesturesBy($teacher, $program);
    }

    /** This teacher's net contribution to one student, cancellations already netted out - shown, never enforced. */
    public function netFor(User $teacher, User $student, Program $program): int
    {
        return $this->entries->gestureNet($teacher, $student, $program);
    }
}
