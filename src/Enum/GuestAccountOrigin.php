<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why an account exists on a machine - which decides who may remove it.
 *
 *   member - it belongs to somebody enrolled in the batch's program. Removing them from the class
 *            is what removes the account, and the syncer does it on the next run.
 *   fixed  - a service account the batch always lays down (`prof`, `sae`). Nothing about a class
 *            roster touches it.
 *   manual - somebody created it inside the machine, and MonCampus never asked for it. **The syncer
 *            leaves these alone.** A console that quietly deleted the account a student made for
 *            their own project would be worse than one that never synchronises at all.
 */
enum GuestAccountOrigin: string
{
    case Member = 'member';
    case Fixed = 'fixed';
    case Manual = 'manual';

    public function labelKey(): string
    {
        return match ($this) {
            self::Member => 'guestAccountMemberLabel',
            self::Fixed => 'guestAccountFixedLabel',
            self::Manual => 'guestAccountManualLabel',
        };
    }

    public function badgeModifier(): string
    {
        return match ($this) {
            self::Member => 'blue',
            self::Fixed => 'teal',
            self::Manual => 'gray',
        };
    }
}
