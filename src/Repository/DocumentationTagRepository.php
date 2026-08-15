<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentationTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentationTag>
 *
 * @phpstan-type TagUsageRow array{tag: DocumentationTag, usages: int}
 */
class DocumentationTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentationTag::class);
    }

    public function findOneByLabel(string $label): ?DocumentationTag
    {
        return $this->findOneBy(['normalizedLabel' => DocumentationTag::normalize($label)]);
    }

    /**
     * Every tag with how many articles wear it - the autocompletion list of 2d shows the count,
     * and the administration screen sorts on it.
     *
     * @return list<TagUsageRow>
     */
    public function findAllWithUsage(): array
    {
        /** @var list<array{0: DocumentationTag, usages: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t', 'COUNT(a.id) AS usages')
            ->leftJoin('t.articles', 'a')
            ->groupBy('t.id')
            ->orderBy('t.label', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['tag' => $row[0], 'usages' => (int) $row['usages']],
            $rows,
        );
    }

    /**
     * The tags actually worn by at least one article of a set - what the left column of 2a/2b
     * lists, so a tag nobody uses in the browsed section is not offered as a dead filter.
     *
     * @param list<int> $articleIds
     *
     * @return list<TagUsageRow>
     */
    public function findUsedByArticles(array $articleIds): array
    {
        if ([] === $articleIds) {
            return [];
        }

        /** @var list<array{0: DocumentationTag, usages: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t', 'COUNT(a.id) AS usages')
            ->innerJoin('t.articles', 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $articleIds)
            ->groupBy('t.id')
            ->orderBy('t.label', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['tag' => $row[0], 'usages' => (int) $row['usages']],
            $rows,
        );
    }

    /** @return list<DocumentationTag> */
    public function findMatching(string $term, int $limit = 10): array
    {
        /** @var list<DocumentationTag> $tags */
        $tags = $this->createQueryBuilder('t')
            ->where('t.normalizedLabel LIKE :term')
            ->setParameter('term', '%'.DocumentationTag::normalize($term).'%')
            ->orderBy('t.label', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $tags;
    }
}
