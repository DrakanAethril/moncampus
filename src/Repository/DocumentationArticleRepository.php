<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DocumentationArticle;
use App\Enum\DocumentationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentationArticle>
 *
 * @phpstan-type PerimeterReadRow array{groupId: int, name: string, reads: int}
 */
class DocumentationArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentationArticle::class);
    }

    /**
     * The articles a screen may consider, narrowed by everything SQL can narrow: the browsed
     * section of the perimeter, the reader's own perimeter, a tag, a search.
     *
     * The audience half of the reading rule (Étudiants/Enseignants/Personnels/Tuteurs) is
     * deliberately *not* applied here but by App\Service\DocumentationAccess on the rows this
     * returns: it lives in a JSON column, and matching a JSON list in DQL costs more in
     * LIKE-shaped traps than it saves on a base of a few hundred articles.
     *
     * @param list<int>|null $scopeGroupIds  the browsed section and everything below it, null for the whole base
     * @param list<int>|null $readerGroupIds the reader's own groups and their ancestors, null to skip the check (manager)
     *
     * @return list<DocumentationArticle>
     */
    public function findCandidates(
        ?array $scopeGroupIds,
        ?array $readerGroupIds,
        ?int $tagId = null,
        ?string $search = null,
        bool $includeUnpublished = false,
        ?\DateTimeImmutable $now = null,
    ): array {
        // DISTINCT because the perimeter joins below multiply the root row per matching group -
        // without it the same article comes back two or three times on a screen.
        $qb = $this->createQueryBuilder('a')
            ->distinct()
            ->leftJoin('a.author', 'author')->addSelect('author')
            ->orderBy('a.publishedAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        $this->applyPerimeter($qb, 'scope', $scopeGroupIds);
        $this->applyPerimeter($qb, 'reader', $readerGroupIds);

        if (null !== $tagId) {
            // Its own join: reusing 't' above would reduce the article's tags to the filtered one.
            $qb->innerJoin('a.tags', 'ft')->andWhere('ft.id = :tagId')->setParameter('tagId', $tagId);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('a.title LIKE :search OR a.excerpt LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        if (!$includeUnpublished) {
            $this->applyPublished($qb, $now ?? new \DateTimeImmutable());
        }

        /** @var list<DocumentationArticle> $articles */
        $articles = $qb->getQuery()->getResult();

        return $articles;
    }

    /**
     * The management list of 2g/2h: every article, whatever its status, newest first, paged.
     *
     * @param list<int>|null $scopeGroupIds
     *
     * @return list<DocumentationArticle>
     */
    public function findPage(int $offset, int $limit, ?array $scopeGroupIds = null, string $sort = 'reads', bool $sinceReset = true, bool $neverReadOnly = false): array
    {
        // No fetch-join of the tags collection here, unlike findCandidates(): a to-many fetch join
        // under setMaxResults() slices SQL rows, not articles, and the page comes back short.
        $qb = $this->createQueryBuilder('a')
            ->distinct()
            ->leftJoin('a.author', 'author')->addSelect('author')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $this->applyPerimeter($qb, 'scope', $scopeGroupIds);
        $this->applyListFilters($qb, $neverReadOnly);

        if ('reads' === $sort) {
            $qb->orderBy($sinceReset ? 'a.readCountSinceReset' : 'a.readCount', 'DESC');
        }

        $qb->addOrderBy('a.publishedAt', 'DESC')->addOrderBy('a.id', 'DESC');

        /** @var list<DocumentationArticle> $articles */
        $articles = $qb->getQuery()->getResult();

        return $articles;
    }

    /** @param list<int>|null $scopeGroupIds */
    public function countPage(?array $scopeGroupIds = null, bool $neverReadOnly = false): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(DISTINCT a.id)');
        $this->applyPerimeter($qb, 'scope', $scopeGroupIds);
        $this->applyListFilters($qb, $neverReadOnly);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countByStatus(DocumentationStatus $status): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countNeverRead(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.readCount = 0')
            ->andWhere('a.status = :published')
            ->setParameter('published', DocumentationStatus::Published)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array{total: int, sinceReset: int} */
    public function sumReads(): array
    {
        /** @var array{total: int|string|null, sinceReset: int|string|null} $row */
        $row = $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.readCount), 0) AS total, COALESCE(SUM(a.readCountSinceReset), 0) AS sinceReset')
            ->getQuery()
            ->getSingleResult();

        return ['total' => (int) $row['total'], 'sinceReset' => (int) $row['sinceReset']];
    }

    /**
     * Reads aggregated per perimeter group - the "Lectures par périmètre" bars of 2f. An article
     * posted on several groups counts once per group, which is what the bars mean: how much each
     * section is read, not how the campus total splits.
     *
     * @return list<PerimeterReadRow>
     */
    public function sumReadsByPerimeter(bool $sinceReset = true): array
    {
        $counter = $sinceReset ? 'a.readCountSinceReset' : 'a.readCount';

        /** @var list<array{groupId: int|string, name: string, reads: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select(\sprintf('g.id AS groupId, g.name AS name, COALESCE(SUM(%s), 0) AS reads', $counter))
            ->innerJoin('a.perimeter', 'g')
            ->groupBy('g.id')
            ->addGroupBy('g.name')
            ->orderBy('reads', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'groupId' => (int) $row['groupId'],
                'name' => $row['name'],
                'reads' => (int) $row['reads'],
            ],
            $rows,
        );
    }

    /**
     * Zeroes every "depuis la remise à zéro" counter in one statement - the historical total is
     * untouched, which is the whole point of keeping two columns.
     */
    public function resetCountersSinceReset(): void
    {
        $this->createQueryBuilder('a')
            ->update()
            ->set('a.readCountSinceReset', '0')
            ->getQuery()
            ->execute();
    }

    /**
     * One UPDATE rather than a read-modify-write on a hydrated entity: an article page is read by
     * many people at once and two of them must not lose a count to each other.
     */
    public function incrementReadCounters(int $articleId): void
    {
        $this->createQueryBuilder('a')
            ->update()
            ->set('a.readCount', 'a.readCount + 1')
            ->set('a.readCountSinceReset', 'a.readCountSinceReset + 1')
            ->where('a.id = :id')
            ->setParameter('id', $articleId)
            ->getQuery()
            ->execute();
    }

    /** @param list<int>|null $groupIds */
    private function applyPerimeter(QueryBuilder $qb, string $alias, ?array $groupIds): void
    {
        if (null === $groupIds) {
            return;
        }

        if ([] === $groupIds) {
            // No group at all matches nothing - said explicitly, or the IN () below is a SQL error.
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->innerJoin('a.perimeter', $alias.'Group')
            ->andWhere(\sprintf('%sGroup.id IN (:%sIds)', $alias, $alias))
            ->setParameter($alias.'Ids', $groupIds);
    }

    private function applyPublished(QueryBuilder $qb, \DateTimeImmutable $now): void
    {
        $qb->andWhere('a.status = :published')
            ->andWhere('a.publishStart IS NULL OR a.publishStart <= :now')
            ->andWhere('a.publishEnd IS NULL OR a.publishEnd >= :now')
            ->setParameter('published', DocumentationStatus::Published)
            ->setParameter('now', $now);
    }

    private function applyListFilters(QueryBuilder $qb, bool $neverReadOnly): void
    {
        $qb->andWhere('a.status = :published')->setParameter('published', DocumentationStatus::Published);

        if ($neverReadOnly) {
            $qb->andWhere('a.readCount = 0');
        }
    }
}
