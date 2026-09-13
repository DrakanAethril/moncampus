<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardOffer;
use App\Entity\JobboardSource;
use App\Entity\Track;
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
    public function findOneByIdentity(Track $track, JobboardSource $source, string $sourceRef): ?JobboardOffer
    {
        return $this->findOneBy(['track' => $track, 'source' => $source, 'sourceRef' => $sourceRef]);
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
    public function findByIdentities(Track $track, array $identities): array
    {
        if ([] === $identities) {
            return [];
        }

        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.track = :track')
            ->setParameter('track', $track);

        $or = $qb->expr()->orX();
        foreach ($identities as $index => $identity) {
            $or->add($qb->expr()->andX('o.source = :source'.$index, 'o.sourceRef = :ref'.$index));
            $qb->setParameter('source'.$index, $identity['source']);
            $qb->setParameter('ref'.$index, $identity['ref']);
        }

        $found = [];
        /** @var JobboardOffer $offer */
        foreach ($qb->andWhere($or)->getQuery()->getResult() as $offer) {
            $found[$offer->getSource()->getSlug().'|'.$offer->getSourceRef()] = $offer;
        }

        return $found;
    }

    /**
     * How many offers each source carries, keyed by source id - what « Configuration > Jobboard >
     * Sources » needs to say whether a row may be deleted, in one query rather than one per row.
     *
     * @return array<int, int>
     */
    public function countBySource(): array
    {
        $counts = [];

        /** @var array{source: int, total: int} $row */
        foreach ($this->createQueryBuilder('o')
            ->select('IDENTITY(o.source) AS source, COUNT(o.id) AS total')
            ->groupBy('o.source')
            ->getQuery()
            ->getResult() as $row) {
            $counts[(int) $row['source']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * How many offers of `$from` already have a twin on `$into` - the same filière and the same
     * `source_ref`. Merging two sources would make those two rows one identity, and the UNIQUE
     * index is the only thing that could then say which one survives. So the screen refuses the
     * merge and names the number instead: an offer is never deleted to make a merge fit.
     */
    public function countIdentityCollisions(JobboardSource $from, JobboardSource $into): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->innerJoin(JobboardOffer::class, 'b', 'WITH', 'b.track = a.track AND b.sourceRef = a.sourceRef AND b.source = :into')
            ->andWhere('a.source = :from')
            ->setParameter('from', $from)
            ->setParameter('into', $into)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every offer of one site, erased - what blacklisting a source costs, and the one place on the
     * jobboard where an offer is deleted rather than closed.
     *
     * Closing them would have been the habit of this module (« rien n'est jamais effacé »), and it
     * is wrong here: a closed offer is an advert that left its site, which is a fact about the
     * market. A blacklisted site is a decision about the board, and the offers must leave the
     * screens rather than sit in the history of a site nobody wants to read about. Un-blacklisting
     * does not bring them back - the next pass does, minus a `premiere_vue` nothing can rebuild,
     * which is exactly why the screen asks before doing it.
     */
    public function deleteBySource(JobboardSource $source): int
    {
        return (int) $this->createQueryBuilder('o')
            ->delete()
            ->andWhere('o.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();
    }

    /** Every offer filed under one source - what a merge moves, one by one. */
    public function moveToSource(JobboardSource $from, JobboardSource $into): int
    {
        return (int) $this->createQueryBuilder('o')
            ->update()
            ->set('o.source', ':into')
            ->andWhere('o.source = :from')
            ->setParameter('from', $from)
            ->setParameter('into', $into)
            ->getQuery()
            ->execute();
    }

    /**
     * The sources actually present inside a reading perimeter - what the « Source » filter offers.
     * Never a list written in advance: a menu proposing a site whose offers are in another filière
     * is a filter that returns nothing.
     *
     * @param list<Track> $tracks
     *
     * @return list<JobboardSource>
     */
    public function findSourcesInPerimeter(array $tracks): array
    {
        // The ids first, the rows second, rather than one query selecting the joined side: the
        // perimeter must keep entering through createOpenOffersQueryBuilder(), and DQL cannot
        // select a joined entity without its root anyway.
        $ids = [];

        /** @var array{id: int} $row */
        foreach ($this->createOpenOffersQueryBuilder($tracks)
            ->select('DISTINCT IDENTITY(o.source) AS id')
            ->getQuery()
            ->getScalarResult() as $row) {
            $ids[] = (int) $row['id'];
        }

        if ([] === $ids) {
            return [];
        }

        /** @var list<JobboardSource> $sources */
        $sources = $this->getEntityManager()
            ->getRepository(JobboardSource::class)
            ->findBy(['id' => $ids], ['label' => 'ASC']);

        return $sources;
    }

    /**
     * The one place the reading perimeter enters a query. Callers add their filters on top; none of
     * them may widen this - see App\Service\Jobboard\JobboardOfferFinder.
     *
     * @param list<Track> $tracks
     */
    public function createOpenOffersQueryBuilder(array $tracks, string $alias = 'o'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere($alias.'.track IN (:perimeter)')
            ->andWhere($alias.'.closedAt IS NULL')
            ->setParameter('perimeter', $tracks);
    }
}
