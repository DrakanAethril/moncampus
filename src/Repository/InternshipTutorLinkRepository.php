<?php

namespace App\Repository;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipTutorLink>
 */
class InternshipTutorLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipTutorLink::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipTutorLink> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.student', 'st')->addSelect('st')
            ->leftJoin('l.tutor', 'tu')->addSelect('tu')
            ->leftJoin('l.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('l.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('l.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('l.program = :program')
            ->setParameter('program', $program)
            ->orderBy('l.id', 'DESC')
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

        $qb->andWhere('l.tutorFirstName LIKE :search OR l.tutorLastName LIKE :search OR l.companyName LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }
}
