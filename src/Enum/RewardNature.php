<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What kind of thing a reward is - and it is the nature that decides the whole life cycle (§5.5).
 *
 * - **Symbolic**: an avatar frame, a badge, a displayed title, a line on the promo's wall. Acquired
 *   for good, shown on the profile, and **never taken back** - not even after a malus (§5.6).
 * - **Consumable**: the choice of a TP subject, the choice of a partner, a seat at the front for a
 *   demonstration. Used once,
 *   spent by the student themselves, with the date it was spent on kept.
 * - **Offline**: a place on an outing, a breakfast, a company visit. The application notifies it and
 *   remembers it, and does nothing else with it - the rest happens outside.
 *
 * Whatever the nature: **a reward never gives points and never moves the index.** If it did, a
 * teacher could lift a student above the others with one click and outside their envelope, and the
 * whole equity of §2 would leave through that door. The chain runs one way: points make the index,
 * the index makes the tiers, the tiers and a human hand make the rewards.
 */
enum RewardNature: string
{
    case Symbolic = 'symbolic';
    case Consumable = 'consumable';
    case Offline = 'offline';

    public function labelKey(): string
    {
        return match ($this) {
            self::Symbolic => 'rewardNatureSymbolicLabel',
            self::Consumable => 'rewardNatureConsumableLabel',
            self::Offline => 'rewardNatureOfflineLabel',
        };
    }

    public function lifecycleKey(): string
    {
        return match ($this) {
            self::Symbolic => 'rewardNatureSymbolicLifecycleText',
            self::Consumable => 'rewardNatureConsumableLifecycleText',
            self::Offline => 'rewardNatureOfflineLifecycleText',
        };
    }

    /** Only a consumable is spent, and the student spends it themselves. */
    public function isSpendable(): bool
    {
        return self::Consumable === $this;
    }

    /** The business order of a listing - never `ORDER BY nature`, which sorts the stored values. */
    public function rank(): int
    {
        return match ($this) {
            self::Consumable => 1,
            self::Symbolic => 2,
            self::Offline => 3,
        };
    }
}
