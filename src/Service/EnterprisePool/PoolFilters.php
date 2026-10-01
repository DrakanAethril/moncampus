<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Entity\CompanySearchCategory;
use App\Entity\Option;
use App\Entity\Track;
use App\Enum\HostingKind;

/**
 * What the « Vivier » list was asked (design/validated/vivier-entreprises.md §8.1). Read by
 * PoolListing, redrawn by the screen. `kinds` is only ever filled for an administrator - the
 * controller drops it for anybody else, whatever the URL says (D7).
 */
final readonly class PoolFilters
{
    /**
     * @param list<string>                $departments
     * @param list<CompanySearchCategory> $categories
     * @param list<HostingKind>           $kinds
     */
    public function __construct(
        public string $name = '',
        public array $departments = [],
        public array $categories = [],
        public ?Track $track = null,
        public ?Option $option = null,
        public ?int $sinceYear = null,
        public array $kinds = [],
        public bool $withTeacherContact = false,
        public bool $pendingSiretOnly = false,
        public int $page = 1,
    ) {
    }

    /** @return array<string, string|int|list<string|int>> */
    public function toQuery(?int $page = null): array
    {
        return array_filter([
            'name' => $this->name,
            'deps' => implode(', ', $this->departments),
            'cat' => array_map(static fn (CompanySearchCategory $category): int => (int) $category->getId(), $this->categories),
            'track' => $this->track?->getId() ?? '',
            'option' => $this->option?->getId() ?? '',
            'since' => $this->sinceYear ?? '',
            'kind' => array_map(static fn (HostingKind $kind): string => $kind->value, $this->kinds),
            'teacher' => $this->withTeacherContact ? 1 : '',
            'pending' => $this->pendingSiretOnly ? 1 : '',
            'page' => $page ?? $this->page,
        ], static fn (mixed $value): bool => '' !== $value && [] !== $value);
    }
}
