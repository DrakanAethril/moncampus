<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a word cloud stands: `Programmé` → `Ouvert` → `Clos`.
 *
 * **Derived, never stored.** The three answers are read off the availability window and the two
 * manual stamps by App\Service\WordCloud\WordCloudSchedule, on the same principle as
 * QuizInstance::isOpenNow(): a stored status is a second source of truth that goes stale the
 * minute the clock passes the closing time with nobody looking at the screen.
 */
enum WordCloudStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';

    public function labelKey(): string
    {
        return match ($this) {
            self::Scheduled => 'wordCloudStatusScheduledLabel',
            self::Open => 'wordCloudStatusOpenLabel',
            self::Closed => 'wordCloudStatusClosedLabel',
        };
    }

    /** The tag's colour on the list and on the title line. */
    public function badgeModifier(): string
    {
        return match ($this) {
            self::Scheduled => 'cm-wc-tag--scheduled',
            self::Open => 'cm-wc-tag--open',
            self::Closed => 'cm-wc-tag--closed',
        };
    }
}
