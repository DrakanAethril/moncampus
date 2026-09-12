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
