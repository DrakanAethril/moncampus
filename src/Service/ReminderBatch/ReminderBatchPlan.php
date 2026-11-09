<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

use App\Enum\ReminderBatchExclusion;

/**
 * What ReminderBatchPlanner decided: one line per alternance, to send or set aside.
 *
 * @phpstan-type PlanLine array{tutorLinkId: int, programId: int, exclusion: ReminderBatchExclusion|null}
 */
final readonly class ReminderBatchPlan
{
    /**
     * @param list<PlanLine> $lines
     */
    public function __construct(
        public array $lines,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }

    public function waitingCount(): int
    {
        return \count($this->lines);
    }

    public function toSendCount(): int
    {
        return \count(array_filter($this->lines, static fn (array $line): bool => null === $line['exclusion']));
    }

    public function excludedCount(): int
    {
        return $this->waitingCount() - $this->toSendCount();
    }

    /**
     * @return array<int, array{waiting: int, toSend: int, excluded: int}>
     */
    public function countsByProgram(): array
    {
        $counts = [];
        foreach ($this->lines as $line) {
            $counts[$line['programId']] ??= ['waiting' => 0, 'toSend' => 0, 'excluded' => 0];
            ++$counts[$line['programId']]['waiting'];
            ++$counts[$line['programId']][null === $line['exclusion'] ? 'toSend' : 'excluded'];
        }

        return $counts;
    }
}
