<?php

declare(strict_types=1);

namespace App\Counter;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A value the platform stores rather than recomputes at display, and can recompute on demand.
 *
 * The rule every implementation keeps: the stored value moves **in real time**, in the same
 * transaction as whatever changes it; the recomputation is a safety net, run at night by
 * `app:counters:recompute` and by the buttons that offer it. A counter that only became right at
 * night would be a cache with a twelve-hour lag, not a counter.
 *
 * recompute() must read the source and write the value in a way a concurrent live update cannot
 * slip between - lock the rows it corrects before it reads the source.
 */
#[AutoconfigureTag(self::TAG)]
interface RecomputableCounter
{
    public const string TAG = 'app.recomputable_counter';

    /** The name `--counter=` takes, stable across releases: `equipment_stock`. */
    public function name(): string;

    /** One French line saying what it counts, for the command's listing. */
    public function description(): string;

    /**
     * Checks every row it keeps, or only `$id`, against its source, and corrects the ones that
     * drifted unless `$dryRun`.
     */
    public function recompute(?int $id = null, bool $dryRun = false): CounterRun;
}
