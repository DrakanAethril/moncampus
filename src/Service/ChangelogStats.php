<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ReleaseEntryType;

/**
 * The few things the changelog can say about itself, for the sidebar of /changelog.
 *
 * They are there to be read for pleasure, which is exactly why they are computed rather than
 * written: a figure nobody can check is decoration, and decoration that presents itself as a
 * measurement is worse than none at all. Everything here is derived from the release list, so it
 * moves on its own at each deploy.
 *
 * @phpstan-type Stats array{
 *     busiest: array{version: string, count: int}|null,
 *     longestStreak: int,
 *     weekday: int|null,
 *     weekdayCount: int,
 *     newPerFix: float|null
 * }
 */
class ChangelogStats
{
    /**
     * @param list<Release> $releases
     *
     * @return Stats
     */
    public function of(array $releases): array
    {
        return [
            'busiest' => $this->busiest($releases),
            'longestStreak' => $this->longestStreak($releases),
            ...$this->favouriteWeekday($releases),
            'newPerFix' => $this->newPerFix($releases),
        ];
    }

    /** @param list<Release> $releases */
    private function busiest(array $releases): ?array
    {
        $best = null;

        foreach ($releases as $release) {
            $count = count($release->entries);

            if (0 !== $count && (null === $best || $count > $best['count'])) {
                $best = ['version' => $release->version, 'count' => $count];
            }
        }

        return $best;
    }

    /**
     * The longest run of consecutive calendar days that each carried at least one release.
     *
     * Days, not releases: two deploys on the same afternoon are one day of shipping, and counting
     * them twice would turn a busy Friday into a fake streak.
     *
     * @param list<Release> $releases
     */
    private function longestStreak(array $releases): int
    {
        $days = array_values(array_unique(array_map(
            static fn (Release $release): string => $release->date->format('Y-m-d'),
            $releases,
        )));
        sort($days);

        $longest = 0;
        $current = 0;
        $previous = null;

        foreach ($days as $day) {
            $date = new \DateTimeImmutable($day);
            $current = (null !== $previous && 1 === (int) $previous->diff($date)->days) ? $current + 1 : 1;
            $longest = max($longest, $current);
            $previous = $date;
        }

        return $longest;
    }

    /**
     * @param list<Release> $releases
     *
     * @return array{weekday: int|null, weekdayCount: int}
     */
    private function favouriteWeekday(array $releases): array
    {
        /** @var array<int, int> $tally */
        $tally = [];

        foreach ($releases as $release) {
            $day = (int) $release->date->format('N');
            $tally[$day] = ($tally[$day] ?? 0) + 1;
        }

        if ([] === $tally) {
            return ['weekday' => null, 'weekdayCount' => 0];
        }

        $best = array_keys($tally, max($tally), true)[0];

        return ['weekday' => $best, 'weekdayCount' => $tally[$best]];
    }

    /**
     * How many new things shipped for each thing repaired.
     *
     * Null without a single fix - not "infinite", not zero: a changelog that has never recorded a
     * repair has nothing to say about the ratio, and inventing a number there would be the exact
     * decoration this class refuses.
     *
     * @param list<Release> $releases
     */
    private function newPerFix(array $releases): ?float
    {
        $features = 0;
        $fixes = 0;

        foreach ($releases as $release) {
            foreach ($release->entries as $entry) {
                $features += ReleaseEntryType::Feature === $entry->type ? 1 : 0;
                $fixes += ReleaseEntryType::Fix === $entry->type ? 1 : 0;
            }
        }

        return 0 === $fixes ? null : round($features / $fixes, 1);
    }
}
