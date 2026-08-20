<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where one machine of a batch stands.
 *
 * A batch is deliberately not atomic: twenty-four machines are twenty-four independent creations,
 * and one refusal from the hypervisor must not undo the twenty-three that worked. So each item
 * carries its own state, and the batch screen offers to **resume** - which is only meaningful
 * because `failed` and `planned` are distinguishable from `created`.
 */
enum VmBatchItemStatus: string
{
    /** Named and addressed, nothing asked of the hypervisor yet. */
    case Planned = 'planned';

    /** The creation call went out; the task is under way. */
    case Creating = 'creating';

    /** The machine exists. */
    case Created = 'created';

    /** It exists and its accounts have been brought in line. */
    case Provisioned = 'provisioned';

    case Failed = 'failed';

    public function labelKey(): string
    {
        return match ($this) {
            self::Planned => 'vmBatchItemPlannedLabel',
            self::Creating => 'vmBatchItemCreatingLabel',
            self::Created => 'vmBatchItemCreatedLabel',
            self::Provisioned => 'vmBatchItemProvisionedLabel',
            self::Failed => 'vmBatchItemFailedLabel',
        };
    }

    public function badgeModifier(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Creating => 'gold',
            self::Created => 'blue',
            self::Provisioned => 'green',
            self::Failed => 'red',
        };
    }

    /**
     * Whether resuming the batch has anything left to do about this item.
     *
     * Every status but Provisioned qualifies, because a pass advances an item by one step rather
     * than finishing it: a machine still being cloned, or booted but not answering yet, is
     * outstanding work exactly like one that was never started. Only Provisioned is done.
     */
    public function isResumable(): bool
    {
        return self::Provisioned !== $this;
    }
}
