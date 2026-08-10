<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ReleaseEntryType;

/**
 * One production release: a version, the day it went live, a couple of sentences saying what it is
 * about, and the lines that make it up.
 *
 * $summary is the part no tool writes - it is what somebody reading the changelog for thirty
 * seconds takes away. The entries are the detail behind it.
 */
final readonly class Release
{
    /** @param list<ReleaseEntry> $entries */
    public function __construct(
        public string $version,
        public \DateTimeImmutable $date,
        public string $summary,
        public array $entries,
    ) {
    }

    /**
     * What the staff can see and use, ordered by type (added, changed, repaired).
     *
     * @return list<ReleaseEntry>
     */
    public function productEntries(): array
    {
        return $this->sorted(array_filter($this->entries, static fn (ReleaseEntry $e): bool => $e->type->isProductFacing()));
    }

    /**
     * Work on the code itself - folded away on the page, kept in the record.
     *
     * @return list<ReleaseEntry>
     */
    public function internalEntries(): array
    {
        return $this->sorted(array_filter($this->entries, static fn (ReleaseEntry $e): bool => !$e->type->isProductFacing()));
    }

    public function has(ReleaseEntryType $type): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, ReleaseEntry> $entries
     *
     * @return list<ReleaseEntry>
     */
    private function sorted(array $entries): array
    {
        $sorted = array_values($entries);
        usort($sorted, static fn (ReleaseEntry $a, ReleaseEntry $b): int => $a->type->weight() <=> $b->type->weight());

        return $sorted;
    }
}
