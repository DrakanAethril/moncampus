<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * The difference between what MonCampus wants on a machine and what the machine has.
 *
 * Four buckets, not two, and the fourth is the one that carries the design: `unchanged` is what
 * makes a second run of the syncer say "nothing to do" instead of re-creating everything, and
 * `toRemove` is a *proposal* rather than an instruction. Deleting a person's home directory is not
 * something to do on a schedule, so the syncer names them and an administrator decides - and
 * `kept` records that decision so the same proposal does not come back every run.
 */
final readonly class AccountPlan
{
    /**
     * @param list<DesiredAccount> $toCreate  wanted, not there
     * @param list<DesiredAccount> $unchanged wanted, there
     * @param list<string>         $toRemove  there, no longer wanted - proposed, never automatic
     * @param list<string>         $untouched there, never MonCampus's business (`manual`) or
     *                                        deliberately kept
     */
    public function __construct(
        public array $toCreate,
        public array $unchanged,
        public array $toRemove,
        public array $untouched,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->toCreate && [] === $this->toRemove;
    }

    public function createCount(): int
    {
        return \count($this->toCreate);
    }

    public function removeCount(): int
    {
        return \count($this->toRemove);
    }
}
