<?php

namespace App\Repository;

use App\Entity\InternshipSkillLevel;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipSkillLevel>
 */
class InternshipSkillLevelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipSkillLevel::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipSkillLevel> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('l.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('l.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('l.program = :program')
            ->setParameter('program', $program)
            ->orderBy('l.orderIndex', 'ASC')
            ->addOrderBy('l.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // The Centre de formation's own definition (Program::$customSkillLevelsEnabled = false is the
    // default for every Program) - managed at SettingsInternshipController, mirrors the
    // Program-scoped methods above but filters on "no program" instead.
    public function countAllGlobal(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.program IS NULL');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipSkillLevel> */
    public function findPageGlobalOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('l.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('l.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('l.program IS NULL')
            ->orderBy('l.orderIndex', 'ASC')
            ->addOrderBy('l.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the read-only "Niveaux de compétences" view a Program sees while it's still on the
    // Centre de formation's shared definition (Program::$customSkillLevelsEnabled = false).
    /** @return list<InternshipSkillLevel> */
    public function findAllActiveGlobal(): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.program IS NULL')
            ->andWhere('l.inactiveDate IS NULL')
            ->orderBy('l.orderIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers the booklet and the tutor evaluation form - the single place that decides whether a
    // Program reads the Centre de formation's shared levels or its own custom ones, so the two
    // consumers (InternshipBookletBuilder, InternshipTutorEvaluationController::evaluate()) can
    // never drift apart.
    /** @return list<InternshipSkillLevel> */
    public function findAllActiveForProgramOrGlobal(Program $program): array
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('l.inactiveDate IS NULL')
            ->orderBy('l.orderIndex', 'ASC');

        if ($program->isCustomSkillLevelsEnabled()) {
            $qb->andWhere('l.program = :program')->setParameter('program', $program);
        } else {
            $qb->andWhere('l.program IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('l.label LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }
}
