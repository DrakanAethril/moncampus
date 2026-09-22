<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enterprise>
 */
class EnterpriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enterprise::class);
    }

    // Powers InternshipTutorLinkType's "pick an existing enterprise" dropdown.
    /** @return list<Enterprise> */
    public function findAllActiveOrderedByName(?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.inactiveDate IS NULL')
            ->orderBy('e.name', 'ASC');

        // Same asymmetry as ProgramRepository::findAlternanceForSchoolYear(): a test account is
        // confined to test employers, a real one keeps seeing every one of them - somebody has to
        // be able to set a test alternance up in the first place. $viewer is optional so a caller
        // with no user in hand keeps the unfiltered list rather than silently getting one view.
        if ($viewer?->isTestUser()) {
            $qb->andWhere('e.testEnterprise = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * « UFA > Entreprises » - one page of employers, most recently created first.
     *
     * The order is on the creation date rather than the name because the screen opens on « les 20
     * plus récentes »: an employer is typed in as a contract arrives, so the one somebody is
     * looking for right after an import is at the top without searching for it. The search is on
     * the name alone - it is the only thing the list shows, and a match on an address nobody can
     * see reads as a bug.
     *
     * @return list<Enterprise>
     */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, ?User $viewer = null): array
    {
        return $this->queryMatching($search, $viewer)
            ->orderBy('e.creationDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countMatching(?string $search = null, ?User $viewer = null): int
    {
        return (int) $this->queryMatching($search, $viewer)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The rows both of the above read, before either the paging or the counting: same WHERE on both
     * sides, so the total can never describe a different set from the page underneath it.
     */
    // Named queryMatching() rather than matching(): Doctrine's own EntityRepository::matching()
    // is the public Criteria API, and a private override of it is a fatal error at class load.
    private function queryMatching(?string $search, ?User $viewer): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.inactiveDate IS NULL');

        // Same asymmetry as findAllActiveOrderedByName() above.
        if ($viewer?->isTestUser()) {
            $qb->andWhere('e.testEnterprise = true');
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('e.name LIKE :search')->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }
}
