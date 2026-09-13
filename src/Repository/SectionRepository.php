<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Section;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Section>
 */
class SectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Section::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Section> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.ldapGroup', 'g')->addSelect('g')
            ->leftJoin('s.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('s.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('s.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('s.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('s.name LIKE :search OR s.slug LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('s.inactiveDate IS NULL');
        }
    }

    /**
     * Every Section one person belongs to, through the formations they are enrolled in or teach.
     *
     * This is the Jobboard's reading perimeter for a student or a teacher
     * (App\Service\Jobboard\JobboardPerimeter): the filière of an offer is a Section, and being
     * "concerné" by it means having a formation under it. One query rather than walking
     * Program -> Cohort -> Track in PHP, because it lands in a WHERE clause and must not be
     * something a caller can forget to apply.
     *
     * @return list<Section>
     */
    public function findForMember(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('App\\Entity\\Track', 't', 'WITH', 't.section = s')
            ->innerJoin('App\\Entity\\Cohort', 'c', 'WITH', 'c.track = t')
            ->innerJoin('App\\Entity\\Program', 'p', 'WITH', 'p.cohort = c')
            ->leftJoin('p.students', 'st')
            ->leftJoin('p.teachers', 'te')
            ->andWhere('st = :user OR te = :user')
            ->setParameter('user', $user)
            ->distinct()
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every Section, active or not. The Jobboard's perimeter for staff and administrators: a
     * section deactivated in the structure still carries the offers deposited under it, and hiding
     * them would look exactly like losing them.
     *
     * @return list<Section>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers the main navbar's Section entry - fetch-joins each Section's own LDAP group
    // (needed for the nav's per-node visibility check) since this runs on every request.
    /** @return list<Section> */
    public function findActiveForNav(): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('g')
            ->leftJoin('s.ldapGroup', 'g')
            ->where('s.inactiveDate IS NULL')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
