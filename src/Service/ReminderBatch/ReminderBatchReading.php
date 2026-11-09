<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Entity\InternshipReminderBatch;
use App\Enum\ReminderBatchStatus;
use App\Repository\InternshipReminderBatchItemRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * What the batch screens read: each batch's figures, and for a running one when it should be done.
 *
 * The estimate counts the lines of the running batches launched before it, which the pass serves
 * first (R5) - and says so, since « plafond partagé avec un autre lot » is why a batch can go slower
 * than its own size suggests.
 */
final readonly class ReminderBatchReading
{
    public function __construct(
        private InternshipReminderBatchItemRepository $itemRepository,
        private ReminderBatchPace $pace,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<InternshipReminderBatch> $batches
     *
     * @return array<int, ReminderBatchFigures> batch id => figures
     */
    public function figures(array $batches): array
    {
        $counts = $this->itemRepository->countsByBatch($batches);
        $figures = [];
        foreach ($batches as $batch) {
            $figures[(int) $batch->getId()] = ReminderBatchFigures::fromCounts($counts[(int) $batch->getId()] ?? []);
        }

        return $figures;
    }

    /**
     * @return array{endsAt: \DateTimeImmutable|null, nextPassAt: \DateTimeImmutable|null, opensAt: \DateTimeImmutable|null, shared: bool}|null
     */
    public function schedule(InternshipReminderBatch $batch): ?array
    {
        if (ReminderBatchStatus::Running !== $batch->getStatus()) {
            return null;
        }

        $now = $this->clock->now();
        $waiting = $this->itemRepository->countWaitingByRunningBatch();
        $own = $waiting[(int) $batch->getId()] ?? 0;
        $before = 0;
        foreach ($waiting as $batchId => $lines) {
            if ($batchId < (int) $batch->getId()) {
                $before += $lines;
            }
        }

        return [
            'endsAt' => $this->pace->estimateEnd($now, $before + $own),
            'nextPassAt' => $own > 0 ? $this->pace->estimateEnd($now, 1) : null,
            'opensAt' => $this->pace->isOpen($now) ? null : $this->pace->nextOpening($now),
            'shared' => $before > 0,
        ];
    }

    /**
     * When a batch launched now with $lines to send would be done - the line under the form.
     */
    public function estimateForNew(int $lines): ?\DateTimeImmutable
    {
        return $this->pace->estimateEnd($this->clock->now(), array_sum($this->itemRepository->countWaitingByRunningBatch()) + $lines);
    }

    public function perMinute(): int
    {
        return $this->pace->perMinute;
    }
}
