<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * « Projection au tableau »: which of the two boards a cloud is shown on.
 *
 * The setting belongs to the cloud rather than to the projection screen, because it is a decision
 * about the activity - whether the class is meant to watch the shape of the cloud, or to watch
 * words climb a ranking. It stays switchable while projecting all the same: a teacher who sees the
 * room reading the wrong thing should not have to close the board to change it.
 */
enum WordCloudProjectionMode: string
{
    case CloudOnly = 'cloud_only';
    case WithLatest = 'with_latest';

    public function labelKey(): string
    {
        return match ($this) {
            self::CloudOnly => 'wordCloudProjectionCloudOnlyLabel',
            self::WithLatest => 'wordCloudProjectionWithLatestLabel',
        };
    }
}
