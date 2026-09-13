<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardOffer;
use App\Entity\Section;
use App\Enum\JobboardSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardOffer>
 */
class JobboardOfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardOffer::class);
    }

    /**
     * The row an incoming offer is about, or null. The triple is the entity's UNIQUE index, so this
     * is a single-row lookup and not a scan.
     */
    public function findOneByIdentity(Section $section, JobboardSource $source, string $sourceRef): ?JobboardOffer
    {
        return $this->findOneBy(['section' => $section, 'source' => $source, 'sourceRef' => $sourceRef]);
    }

    /**
     * Retention: an advert older than the threshold is deleted, for good.
     *
     * **It is the one place on this side that deletes an offer**, and the distinction with
     * `close()` is the whole point: a closed offer is one that left its site and is kept because
     * how long it stayed online is an information; a purged offer is one nobody will ever consult
     * again, and keeping the row would only make `premiere_vue` a promise about a market that no
     * longer exists.
     *
     * The date read is the publication date, and an offer that never carried one is judged on the
     * day it was first seen. That fallback is not a nicety: `sort_date` holds 1000-01-01 for an
     * undated offer, deliberately, so it lands at the far end of a date-descending list - a
     * threshold read against *that* column would delete every undated offer on the first run.
     */
    public function countPublishedBefore(\DateTimeImmutable $threshold): int
    {
        return (int) $this->olderThan($threshold)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deletePublishedBefore(\DateTimeImmutable $threshold): int
    {
        return (int) $this->olderThan($threshold)
            ->delete()
            ->getQuery()
            ->execute();
    }

    private function olderThan(\DateTimeImmutable $threshold): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->andWhere('(o.publishedAt IS NOT NULL AND o.publishedAt < :threshold) OR (o.publishedAt IS NULL AND o.firstSeenAt < :threshold)')
            ->setParameter('threshold', $threshold);
    }

    /**
     * Every offer of one batch's filière matching a list of (source, ref) pairs, keyed by
     * `source|ref` - what a closing call needs so it can answer line by line without one query per
     * line.
     *
     * @param list<array{source: JobboardSource, ref: string}> $identities
     *
     * @return array<string, JobboardOffer>
     */
    public function findByIdentities(Section $section, array $identities): array
    {
        if ([] === $identities) {
            return [];
        }

        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.section = :section')
            ->setParameter('section', $section);

        $or = $qb->expr()->orX();
        foreach ($identities as $index => $identity) {
            $or->add($qb->expr()->andX('o.source = :source'.$index, 'o.sourceRef = :ref'.$index));
            $qb->setParameter('source'.$index, $identity['source']);
            $qb->setParameter('ref'.$index, $identity['ref']);
        }

        $found = [];
        /** @var JobboardOffer $offer */
        foreach ($qb->andWhere($or)->getQuery()->getResult() as $offer) {
            $found[$offer->getSource()->value.'|'.$offer->getSourceRef()] = $offer;
        }

        return $found;
    }

    /**
     * The one place the reading perimeter enters a query. Callers add their filters on top; none of
     * them may widen this - see App\Service\Jobboard\JobboardOfferFinder.
     *
     * @param list<Section> $sections
     */
    public function createOpenOffersQueryBuilder(array $sections, string $alias = 'o'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere($alias.'.section IN (:perimeter)')
            ->andWhere($alias.'.closedAt IS NULL')
            ->setParameter('perimeter', $sections);
    }
}
