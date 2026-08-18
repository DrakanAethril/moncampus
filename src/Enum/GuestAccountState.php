<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where one account stands between what MonCampus wants and what the machine has.
 *
 * The pair that carries the whole design is `to_remove` and `kept`. The syncer computes a
 * *difference*, so an account that has left the class shows up as "to remove" - but removing a
 * person's home directory is not a thing to do on a schedule, so the screen offers it and an
 * administrator decides. `kept` is the answer to "I saw it, and I am leaving it there", and it has
 * to be recorded or every run would propose the same removal again.
 */
enum GuestAccountState: string
{
    /** Wanted, and not on the machine yet. */
    case ToCreate = 'to_create';

    /** Wanted, and there. */
    case Present = 'present';

    /** On the machine, no longer wanted - proposed for removal, never removed on its own. */
    case ToRemove = 'to_remove';

    /** Proposed for removal and deliberately kept. The syncer stops asking. */
    case Kept = 'kept';

    public function labelKey(): string
    {
        return match ($this) {
            self::ToCreate => 'guestAccountToCreateLabel',
            self::Present => 'guestAccountPresentLabel',
            self::ToRemove => 'guestAccountToRemoveLabel',
            self::Kept => 'guestAccountKeptLabel',
        };
    }

    public function badgeModifier(): string
    {
        return match ($this) {
            self::ToCreate => 'gold',
            self::Present => 'green',
            self::ToRemove => 'red',
            self::Kept => 'gray',
        };
    }

    /** Whether a synchronisation run has anything to do about this account. */
    public function needsAction(): bool
    {
        return self::ToCreate === $this;
    }
}
