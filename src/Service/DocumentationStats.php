<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentationArticle;
use App\Entity\DocumentationCounterReset;
use App\Enum\DocumentationStatus;
use App\Repository\DocumentationArticleRepository;
use App\Repository\DocumentationCounterResetRepository;

/**
 * The figures of the documentation dashboard (handoff 2f) and of its two lists (2g/2h).
 *
 * Everything is counted in SQL rather than over hydrated articles: the dashboard is staff-only and
 * unfiltered by any perimeter, so there is nothing here to narrow and no reason to load 64 rows to
 * count them.
 *
 * @phpstan-import-type PerimeterReadRow from DocumentationArticleRepository
 *
 * @phpstan-type Overview array{published: int, drafts: int, readsTotal: int, readsSinceReset: int, neverRead: int, lastReset: DocumentationCounterReset|null}
 */
class DocumentationStats
{
    public function __construct(
        private readonly DocumentationArticleRepository $articles,
        private readonly DocumentationCounterResetRepository $resets,
    ) {
    }

    /** @return Overview */
    public function overview(): array
    {
        $reads = $this->articles->sumReads();

        return [
            'published' => $this->articles->countByStatus(DocumentationStatus::Published),
            'drafts' => $this->articles->countByStatus(DocumentationStatus::Draft),
            'readsTotal' => $reads['total'],
            'readsSinceReset' => $reads['sinceReset'],
            'neverRead' => $this->articles->countNeverRead(),
            'lastReset' => $this->resets->findLast(),
        ];
    }

    /** @return list<DocumentationArticle> */
    public function mostRead(bool $sinceReset, int $limit): array
    {
        return $this->articles->findPage(0, $limit, sinceReset: $sinceReset);
    }

    /**
     * The bars of "Lectures par périmètre", widest first, as a share of the largest one - the
     * template only has to read a percentage.
     *
     * @return list<array{groupId: int, name: string, reads: int, share: int}>
     */
    public function readsByPerimeter(bool $sinceReset, int $limit = 8): array
    {
        $rows = \array_slice($this->articles->sumReadsByPerimeter($sinceReset), 0, $limit);
        $highest = 0;

        foreach ($rows as $row) {
            $highest = max($highest, $row['reads']);
        }

        return array_map(
            static fn (array $row): array => [
                'groupId' => $row['groupId'],
                'name' => $row['name'],
                'reads' => $row['reads'],
                'share' => $highest > 0 ? (int) round($row['reads'] / $highest * 100) : 0,
            ],
            $rows,
        );
    }
}
