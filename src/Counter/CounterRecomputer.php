<?php

declare(strict_types=1);

namespace App\Counter;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every stored counter of the platform, behind one door - the command and the buttons both go
 * through here, so a counter added later joins them by implementing RecomputableCounter alone.
 *
 * **A drift is never corrected in silence.** Each one is logged at *error* level, which in
 * production reaches Discord (config/packages/monolog.yaml): a counter that drifted means a code
 * path moved a source without moving its counter, and a nightly pass that quietly patched it would
 * hide that bug for ever. A dry run logs nothing - it is somebody looking.
 */
final class CounterRecomputer
{
    /** @var array<string, RecomputableCounter> */
    private array $counters = [];

    /**
     * @param iterable<RecomputableCounter> $counters
     */
    public function __construct(
        #[AutowireIterator(RecomputableCounter::TAG)] iterable $counters,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($counters as $counter) {
            $this->counters[$counter->name()] = $counter;
        }

        ksort($this->counters);
    }

    /** @return array<string, RecomputableCounter> */
    public function counters(): array
    {
        return $this->counters;
    }

    public function has(string $name): bool
    {
        return isset($this->counters[$name]);
    }

    /**
     * @return list<CounterRun>
     */
    public function recomputeAll(bool $dryRun = false): array
    {
        $runs = [];
        foreach (array_keys($this->counters) as $name) {
            $runs[] = $this->recompute($name, null, $dryRun);
        }

        return $runs;
    }

    public function recompute(string $name, ?int $id = null, bool $dryRun = false): CounterRun
    {
        $counter = $this->counters[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown counter "%s".', $name));
        $run = $counter->recompute($id, $dryRun);

        if (!$dryRun) {
            foreach ($run->drifts as $drift) {
                $this->logger->error('Counter drift corrected: {counter} #{id} ({label}): {detail}', [
                    'counter' => $name,
                    'id' => $drift->id,
                    'label' => $drift->label,
                    'detail' => $drift->describe(),
                ]);
            }
        }

        return $run;
    }
}
