<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\SkillGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SkillGroup>
 */
class SkillGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SkillGroup::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('g')->select('COUNT(g.id)')->where('g.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<SkillGroup> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('g.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('g.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('g.program = :program')
            ->setParameter('program', $program)
            ->orderBy('g.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // The Centre de formation's own definition (Program::$customSkillCriteriaEnabled = false is
    // the default for every Program) - managed at SettingsInternshipController, mirrors the
    // Program-scoped methods above but filters on "no program" instead.
    public function countAllGlobal(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('g')->select('COUNT(g.id)')->where('g.program IS NULL');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<SkillGroup> */
    public function findPageGlobalOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('g.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('g.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('g.program IS NULL')
            ->orderBy('g.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the read-only "Groupes de compétences" view a Program sees while it's still on the
    // Centre de formation's shared definition (Program::$customSkillCriteriaEnabled = false).
    /** @return list<SkillGroup> */
    public function findAllActiveGlobalWithSkills(): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('sk')
            ->leftJoin('g.skills', 'sk', 'WITH', 'sk.inactiveDate IS NULL')
            ->where('g.program IS NULL')
            ->andWhere('g.inactiveDate IS NULL')
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Powers the booklet and the tutor evaluation form - the single place that decides whether a
    // Program reads the Centre de formation's shared groups or its own custom ones, so the two
    // consumers (InternshipBookletBuilder, InternshipTutorEvaluationController::evaluate()) can
    // never drift apart. Active skills and gating Options (see SkillGroup::$options) are
    // fetch-joined to avoid N+1 when the caller filters by the student's own Options afterward.
    /** @return list<SkillGroup> */
    public function findAllActiveForProgramOrGlobal(Program $program): array
    {
        $qb = $this->createQueryBuilder('g')
            ->addSelect('sk', 'o')
            ->leftJoin('g.skills', 'sk', 'WITH', 'sk.inactiveDate IS NULL')
            ->leftJoin('g.options', 'o')
            ->andWhere('g.inactiveDate IS NULL')
            ->orderBy('g.id', 'ASC');

        if ($program->isCustomSkillCriteriaEnabled()) {
            $qb->andWhere('g.program = :program')->setParameter('program', $program);
        } else {
            $qb->andWhere('g.program IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('g.label LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('g.inactiveDate IS NULL');
        }
    }
}
