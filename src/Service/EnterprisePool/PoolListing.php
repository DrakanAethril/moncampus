<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Entity\Enterprise;
use App\Entity\EnterpriseTeacherContact;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The « Vivier » list: every employer the establishment knows, with what EnterpriseHostings reads
 * of each, filtered and paged.
 *
 * The company-side filters (name, département, activity) are SQL on the Enterprise and its
 * registry snapshot; the hosting-side ones (kind, filière, option, since) are read off the merged
 * hostings - the UFA's contracts and the stored history live in two tables, and a vivier is a few
 * hundred companies, not a register. Most recent hosting first, then by name.
 *
 * @phpstan-type PoolRow array{enterprise: Enterprise, records: list<HostingRecord>, summary: HostingSummary, lastYear: ?int, teacherContacts: int}
 */
class PoolListing
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EnterpriseHostings $hostings,
    ) {
    }

    /**
     * @return array{rows: list<PoolRow>, total: int, pages: int}
     */
    public function list(PoolFilters $filters, User $viewer): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Enterprise::class, 'e')
            ->where('e.inactiveDate IS NULL')
            ->orderBy('e.name', 'ASC');

        if ($viewer->isTestUser()) {
            $builder->andWhere('e.testEnterprise = true');
        }
        if ('' !== $filters->name) {
            $builder->andWhere('e.name LIKE :name')->setParameter('name', '%'.$filters->name.'%');
        }
        if ([] !== $filters->departments) {
            $clauses = [];
            foreach ($filters->departments as $index => $department) {
                $clauses[] = 'e.postalCode LIKE :dep'.$index;
                $builder->setParameter('dep'.$index, $this->postcodePrefix($department).'%');
            }
            $builder->andWhere(implode(' OR ', $clauses));
        }
        $codes = array_merge(...array_map(static fn ($category): array => $category->getNafCodes(), $filters->categories));
        if ([] !== $codes) {
            $builder->andWhere('e.nafCode IN (:codes)')->setParameter('codes', $codes);
        }
        if ($filters->pendingSiretOnly) {
            $builder->andWhere('e.siretConfirmedAt IS NULL');
        }

        /** @var list<Enterprise> $enterprises */
        $enterprises = $builder->getQuery()->getResult();
        $records = $this->hostings->forEnterprises($enterprises, $viewer);
        $teacherCounts = $this->teacherContactCounts($enterprises);

        $rows = [];
        foreach ($enterprises as $enterprise) {
            $id = (int) $enterprise->getId();
            $kept = array_values(array_filter($records[$id] ?? [], fn (HostingRecord $record): bool => $this->recordMatches($record, $filters)));
            $hostingFilter = null !== $filters->track || null !== $filters->option || null !== $filters->sinceYear || [] !== $filters->kinds;

            if ($hostingFilter && [] === $kept) {
                continue;
            }
            if ([] !== $filters->kinds) {
                foreach ($filters->kinds as $kind) {
                    if ([] === array_filter($kept, static fn (HostingRecord $record): bool => $record->kind === $kind)) {
                        continue 2;
                    }
                }
            }
            if ($filters->withTeacherContact && 0 === ($teacherCounts[$id] ?? 0)) {
                continue;
            }

            $summary = new HostingSummary();
            foreach ($records[$id] ?? [] as $record) {
                $summary->add($record);
            }
            $rows[] = [
                'enterprise' => $enterprise,
                'records' => $records[$id] ?? [],
                'summary' => $summary,
                'lastYear' => isset($records[$id][0]) ? $records[$id][0]->yearStart : null,
                'teacherContacts' => $teacherCounts[$id] ?? 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['lastYear'] ?? 0, mb_strtolower($a['enterprise']->getName())]
            <=> [$a['lastYear'] ?? 0, mb_strtolower($b['enterprise']->getName())]);

        $total = \count($rows);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $filters->page), $pages);

        return [
            'rows' => \array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'pages' => $pages,
        ];
    }

    private function recordMatches(HostingRecord $record, PoolFilters $filters): bool
    {
        return (null === $filters->track || $record->track === $filters->track)
            && (null === $filters->option || $record->option === $filters->option)
            && (null === $filters->sinceYear || $record->yearStart >= $filters->sinceYear)
            && ([] === $filters->kinds || \in_array($record->kind, $filters->kinds, true));
    }

    /** Corsica's postcodes start with 20; overseas départements are three digits already. */
    private function postcodePrefix(string $department): string
    {
        return \in_array($department, ['2A', '2B'], true) ? '20' : $department;
    }

    /**
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, int>
     */
    private function teacherContactCounts(array $enterprises): array
    {
        if ([] === $enterprises) {
            return [];
        }

        /** @var list<array{id: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT IDENTITY(tc.enterprise) AS id, COUNT(tc.id) AS total FROM '.EnterpriseTeacherContact::class.' tc WHERE tc.enterprise IN (:enterprises) GROUP BY tc.enterprise',
        )->setParameter('enterprises', $enterprises)->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }
}
