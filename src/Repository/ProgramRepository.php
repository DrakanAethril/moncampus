<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Program>
 */
class ProgramRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Program::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Program> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.cohort', 'c')->addSelect('c')
            ->leftJoin('p.schoolYear', 'y')->addSelect('y')
            ->leftJoin('p.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('p.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('p.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('p.id', 'DESC')
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

        $qb->andWhere('p.name LIKE :search OR p.shortName LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('p.inactiveDate IS NULL');
        }
    }

    // Populates the "options" and "modalities" collections on an already-fetched page of
    // Programs in two extra queries, instead of one lazy-load query per row per collection -
    // the LEFT JOINs (rather than inner joins) are required so Doctrine also marks each
    // collection as initialized (empty) for Programs with no linked option/modality at all.
    // Two separate queries avoid the row-duplication a single query joining both collections
    // at once would produce.
    /** @param list<Program> $programs */
    public function hydrateOptionsAndModalities(array $programs): void
    {
        if ([] === $programs) {
            return;
        }

        $ids = array_map(static fn (Program $program): ?int => $program->getId(), $programs);

        $this->createQueryBuilder('p')
            ->select('p', 'o')
            ->leftJoin('p.options', 'o')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $this->createQueryBuilder('p')
            ->select('p', 'm')
            ->leftJoin('p.modalities', 'm')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    // Powers the main navbar's Section > Année scolaire > Classe menu: fetch-joins the whole
    // active chain (cohort/track/section, school year, and the cohort's own LDAP group needed
    // for the nav's per-node visibility check) in a single query, since this runs on every
    // request. Grouping by section then by school year happens in
    // StructureNavigationExtension, in the order this query already returns.
    /** @return list<Program> */
    public function findActiveForNav(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 't', 's', 'y', 'cg')
            ->innerJoin('p.cohort', 'c')
            ->innerJoin('c.track', 't')
            ->innerJoin('t.section', 's')
            ->innerJoin('p.schoolYear', 'y')
            ->leftJoin('c.ldapGroup', 'cg')
            ->where('p.inactiveDate IS NULL')
            ->andWhere('c.inactiveDate IS NULL')
            ->andWhere('t.inactiveDate IS NULL')
            ->andWhere('s.inactiveDate IS NULL')
            ->andWhere('y.inactiveDate IS NULL')
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('y.startDate', 'ASC')
            ->addOrderBy('p.shortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Scopes the "instantiate a séquence" target-Program picker (App\Form\SequenceInstantiateType)
    // to Programs a non-staff teacher actually teaches - see SequenceLibraryController.
    /** @return list<Program> */
    public function findAllForTeacher(User $teacher): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.teachers', 't')
            ->addSelect('t')
            ->where('t = :teacher')
            ->andWhere('p.inactiveDate IS NULL')
            ->setParameter('teacher', $teacher)
            ->orderBy('p.shortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // A student belongs to exactly one active Program per school year, but the M2M link to older,
    // now-inactivated Programs is never cleaned up - inactiveDate IS NULL plus this deterministic
    // tiebreak (rather than trusting row order) is what actually enforces "the" active Program for
    // the home dashboard. Returns null for the expected data gap between school years.
    public function findActiveForStudent(User $student): ?Program
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.students', 's')
            ->where('s = :student')
            ->andWhere('p.inactiveDate IS NULL')
            ->setParameter('student', $student)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
