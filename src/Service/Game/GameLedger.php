<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameEntry;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\GameEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place a App\Entity\GameEntry is written, and the three refusals that make the ledger
 * trustworthy (design/validated/gamification.md §8).
 *
 * 1. **A source pays once.** The same (sourceType, sourceId, ruleCode) never produces a second
 *    line, however many times a collector runs over the same submission. That is what allows the
 *    automatic rules to be re-read at will instead of being wired to a fragile one-shot event.
 * 2. **A weekly cap is a refusal, not a truncation.** A wiki revision beyond the second of the week
 *    writes nothing rather than a zero-point line: a journal listing gestures that paid nothing
 *    reads as a bug to the student it is shown to.
 * 3. **Nothing is ever deleted.** Undoing is reverse(), which writes an inverse line pointing at
 *    the one it undoes. A journal one can delete from is not a journal, and a withdrawn gesture has
 *    to remain readable by the person it was addressed to.
 *
 * Nothing here flushes - the caller owns the transaction - so an in-memory guard doubles the
 * database check: within one batch the earlier lines are not yet visible to a query.
 */
final class GameLedger
{
    /** @var array<string, true> what this instance has already written, unflushed included */
    private array $written = [];

    /** @var array<string, int> lines written in this batch, per student, rule and week */
    private array $weekCounts = [];

    public function __construct(
        private readonly GameEntryRepository $entries,
        private readonly GameRuleResolver $rules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Write one line, or refuse.
     *
     * @param int|null $points overrides what the rule pays - a gesture, a mention, an exact credit being cancelled
     *
     * @return GameEntry|null null whenever the line was refused, which is a normal outcome
     */
    public function record(
        User $student,
        Program $program,
        string $ruleCode,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?\DateTimeImmutable $occurredAt = null,
        ?int $points = null,
        ?User $author = null,
        ?string $reason = null,
    ): ?GameEntry {
        $rule = $this->rules->valueOf($program, $ruleCode);

        if (null === $rule || !$rule->enabled) {
            return null;
        }

        $occurredAt ??= new \DateTimeImmutable();
        $value = $points ?? $rule->points;

        $guard = null;
        if (null !== $sourceType && null !== $sourceId) {
            $guard = $student->getId().'|'.$sourceType.'|'.$sourceId.'|'.$ruleCode;

            if (isset($this->written[$guard]) || $this->entries->existsForSource($student, $sourceType, $sourceId, $ruleCode)) {
                return null;
            }
        }

        if (null !== $rule->weeklyCap && !$this->fitsWeeklyCap($student, $ruleCode, $rule->weeklyCap, $occurredAt)) {
            return null;
        }

        if (null !== $guard) {
            $this->written[$guard] = true;
        }
        $this->countWeek($student, $ruleCode, $occurredAt);

        $entry = (new GameEntry($student, $program, $rule->family(), $ruleCode, $value, $occurredAt))
            ->setSource($sourceType, $sourceId)
            ->setAuthor($author)
            ->setReason($reason);

        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * Write a correction on a source that has already paid - the one call that does **not** refuse a
     * second line on the same (sourceType, sourceId, ruleCode).
     *
     * It exists for the relevé and for nothing else. A statement is editable until the period
     * closes, so a card toggled from « net » to « pas net » has to give its thirty points back; the
     * ledger stays append only, and what is written is the *difference*, signed, next to the line
     * that produced it. Deleting the original would be the other way to do it, and it would erase
     * the fact that the week had been stated otherwise.
     */
    public function adjust(
        User $student,
        Program $program,
        string $ruleCode,
        int $points,
        string $sourceType,
        int $sourceId,
        ?\DateTimeImmutable $occurredAt = null,
        ?string $reason = null,
    ): ?GameEntry {
        if (0 === $points) {
            return null;
        }

        $rule = $this->rules->valueOf($program, $ruleCode);

        if (null === $rule) {
            return null;
        }

        $entry = (new GameEntry($student, $program, $rule->family(), $ruleCode, $points, $occurredAt ?? new \DateTimeImmutable()))
            ->setSource($sourceType, $sourceId)
            ->setReason($reason);

        $this->entityManager->persist($entry);

        return $entry;
    }

    /**
     * Undo a line by writing its inverse.
     *
     * The original stays, marked by the line pointing at it; a cancelled gesture is still read by
     * the student it was addressed to, which is the whole point of §9's « annuler écrit une ligne
     * inverse, ne supprime rien ».
     */
    public function reverse(GameEntry $entry, ?User $author = null, ?string $reason = null): GameEntry
    {
        $reversal = (new GameEntry(
            $entry->getStudent(),
            $entry->getProgram(),
            $entry->getFamily(),
            $entry->getRuleCode(),
            -$entry->getPoints(),
        ))
            ->setSource($entry->getSourceType(), $entry->getSourceId())
            ->setAuthor($author)
            ->setReason($reason)
            ->setReversalOf($entry);

        $entry->setResolvedAt(new \DateTimeImmutable());

        $this->entityManager->persist($reversal);

        return $reversal;
    }

    /**
     * The ISO week the date falls in, counted against the cap.
     *
     * Monday to Monday rather than a rolling seven days: a rolling window would let a Sunday-evening
     * batch pass simply by being seven days after the previous one, and a student cannot reason
     * about a window whose start moves with them.
     */
    private function fitsWeeklyCap(User $student, string $ruleCode, int $cap, \DateTimeImmutable $occurredAt): bool
    {
        if ($cap <= 0) {
            return false;
        }

        $start = $occurredAt->modify('monday this week')->setTime(0, 0);
        $end = $start->modify('+7 days');

        // Lines written in this very batch are not in the database yet, and a collector reading a
        // whole period at once is exactly where a cap would otherwise be sailed straight past.
        $already = $this->entries->countInWeek($student, $ruleCode, $start, $end)
            + ($this->weekCounts[$this->weekKey($student, $ruleCode, $start)] ?? 0);

        return $already < $cap;
    }

    private function countWeek(User $student, string $ruleCode, \DateTimeImmutable $occurredAt): void
    {
        $start = $occurredAt->modify('monday this week')->setTime(0, 0);
        $key = $this->weekKey($student, $ruleCode, $start);

        $this->weekCounts[$key] = ($this->weekCounts[$key] ?? 0) + 1;
    }

    private function weekKey(User $student, string $ruleCode, \DateTimeImmutable $weekStart): string
    {
        return $student->getId().'|'.$ruleCode.'|'.$weekStart->format('o-W');
    }
}
