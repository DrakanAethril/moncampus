<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\VisibilityLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Track>
 */
class TrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Track::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Track> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.section', 's')->addSelect('s')
            ->leftJoin('t.ldapGroup', 'g')->addSelect('g')
            ->leftJoin('t.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('t.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('t.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('t.id', 'DESC')
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

        $qb->andWhere('t.name LIKE :search OR t.slug LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('t.inactiveDate IS NULL');
        }
    }

    /**
     * Every Track one person belongs to, through the formations they are enrolled in or teach -
     * kept to the formations that open the Jobboard at one of $tiers.
     *
     * This is the Jobboard's reading perimeter for a student or a teacher
     * (App\Service\Jobboard\JobboardPerimeter): the filière of an offer is a Track, and being
     * "concerné" by it means having a formation under it. One query rather than walking
     * Program -> Cohort -> Track in PHP, because it lands in a WHERE clause and must not be
     * something a caller can forget to apply.
     *
     * The tier travels as a parameter for the same reason it does in
     * App\Security\ProgramTimetableAccess::visibleTiers(): it is the half of the rule that depends
     * on who is reading, and the filtering has to happen in SQL rather than on the rows that come
     * back - a formation whose board is masked must not put its filière in the perimeter at all.
     *
     * @param list<VisibilityLevel> $tiers
     *
     * @return list<Track>
     */
    public function findForMember(User $user, array $tiers): array
    {
        if ([] === $tiers) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->innerJoin('App\\Entity\\Cohort', 'c', 'WITH', 'c.track = t')
            ->innerJoin('App\\Entity\\Program', 'p', 'WITH', 'p.cohort = c')
            ->leftJoin('p.students', 'st')
            ->leftJoin('p.teachers', 'te')
            ->andWhere('st = :user OR te = :user')
            ->andWhere('p.jobboardVisibility IN (:tiers)')
            ->setParameter('user', $user)
            ->setParameter('tiers', $tiers)
            ->distinct()
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every Track carrying at least one formation that opens its Jobboard at one of $tiers,
     * whoever teaches or studies there.
     *
     * The Jobboard's perimeter for the personnel: they are members of no formation, so their
     * reading cannot be a membership - it is the establishment's own, narrowed by what each
     * formation decided. An administrator does not come through here at all (see
     * App\Service\Jobboard\JobboardPerimeter): they garnish and audit the veille, which is not a
     * reading a formation gets to close.
     *
     * @param list<VisibilityLevel> $tiers
     *
     * @return list<Track>
     */
    public function findOpenToJobboard(array $tiers): array
    {
        if ([] === $tiers) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->innerJoin('App\\Entity\\Cohort', 'c', 'WITH', 'c.track = t')
            ->innerJoin('App\\Entity\\Program', 'p', 'WITH', 'p.cohort = c')
            ->andWhere('p.jobboardVisibility IN (:tiers)')
            ->setParameter('tiers', $tiers)
            ->distinct()
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every Track, active or not. The Jobboard's perimeter for an administrator - the one reading
     * no formation gets to close: a filière deactivated in the structure still carries the offers
     * deposited under it, and hiding them would look exactly like losing them.
     *
     * @return list<Track>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
