<?php

namespace App\Repository;

use App\Entity\Option;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Option>
 */
class OptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Option::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('o')->select('COUNT(o.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Option> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.ldapGroup', 'g')->addSelect('g')
            ->leftJoin('o.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('o.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('o.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('o.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the "options" choice field on the Centre de formation's SkillGroupType form, where
    // there's no single Program to scope the choices to (unlike ProgramSettingsController's own
    // use of the same form, which passes $program->getOptions() instead).
    /** @return list<Option> */
    public function findAllActiveOrderedByName(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.inactiveDate IS NULL')
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('o.name LIKE :search OR o.slug LIKE :search OR o.shortName LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('o.inactiveDate IS NULL');
        }
    }
}
