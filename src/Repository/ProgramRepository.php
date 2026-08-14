<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\SchoolYear;
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

    /**
     * The ids of every Program this user is enrolled in as a student, and its sibling below for
     * teaching. Ids rather than entities, and deliberately unfiltered by active state: they answer
     * App\Service\AudienceResolver's membership question, which reads whichever Programs a target
     * actually names - filtering here would make an audience silently narrower than the list
     * resolveRecipients() builds off the same join tables.
     *
     * @return list<int>
     */
    public function findIdsWithUserAsStudent(User $user): array
    {
        return $this->findIdsWithUserIn('students', $user);
    }

    /** @return list<int> */
    public function findIdsWithUserAsTeacher(User $user): array
    {
        return $this->findIdsWithUserIn('teachers', $user);
    }

    /**
     * $association is a hardcoded caller-side constant ('students'/'teachers'), never user input -
     * the two public methods above are the only callers and DQL has no parameter slot for a field
     * name anyway.
     *
     * @return list<int>
     */
    private function findIdsWithUserIn(string $association, User $user): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS programId')
            ->innerJoin('p.'.$association, 'u')
            ->where('u = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(intval(...), array_column($rows, 'programId'));
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

    // Powers the main navbar's Section > Année scolaire > Classe menu, and every Program-audience
    // picker that offers "every active Program" (Message compose/SignupList's staff branch,
    // Announcement, AgendaEvent) - fetch-joins the whole active chain (cohort/track/section,
    // school year, and the cohort's own LDAP group needed for the nav's per-node visibility
    // check) in a single query, since this runs on every request. Grouping by section then by
    // school year happens in StructureNavigationExtension, in the order this query already
    // returns. $viewer's roles are filtered against each Program's own Program::$visibility
    // tier (VisibilityLevel::allowsRoles()) - PHP-side, after the query, rather than in DQL:
    // consistent with how nav visibility is already filtered PHP-side elsewhere, and simpler
    // than a DQL CASE WHEN across 5 enum values.
    /** @return list<Program> */
    public function findActiveForNav(User $viewer): array
    {
        $programs = $this->createQueryBuilder('p')
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

        $roles = $viewer->getRoles();
        $testViewer = $viewer->isTestUser();

        return array_values(array_filter(
            $programs,
            // A test account is confined to test Programs; a real account keeps seeing what it
            // always did, test ones included (the nav pools them into TEST ZONE, and staff have to
            // be able to set them up) - see App\Security\StructureAccessChecker::matchesTestMode().
            static fn (Program $program): bool => (!$testViewer || $program->isTestProgram())
                && $program->getVisibility()->allowsRoles($roles),
        ));
    }

    // Scopes the "instantiate a séquence" target-Program picker (App\Form\SequenceInstantiateType),
    // Quiz launch, and the Message/SignupList/Program-audience pickers' teacher branch to Programs
    // a non-staff teacher actually teaches - see SequenceLibraryController. Same
    // Program::$visibility filtering as findActiveForNav(), see its docblock.
    /** @return list<Program> */
    public function findAllForTeacher(User $teacher): array
    {
        $programs = $this->createQueryBuilder('p')
            ->innerJoin('p.teachers', 't')
            ->addSelect('t')
            ->where('t = :teacher')
            ->andWhere('p.inactiveDate IS NULL')
            ->setParameter('teacher', $teacher)
            ->orderBy('p.shortName', 'ASC')
            ->getQuery()
            ->getResult();

        $roles = $teacher->getRoles();
        $testViewer = $teacher->isTestUser();

        return array_values(array_filter(
            $programs,
            static fn (Program $program): bool => (!$testViewer || $program->isTestProgram())
                && $program->getVisibility()->allowsRoles($roles),
        ));
    }

    // A student belongs to exactly one active Program per school year, but the M2M link to older,
    // now-inactivated Programs is never cleaned up - inactiveDate IS NULL plus this deterministic
    // tiebreak (rather than trusting row order) is what actually enforces "the" active Program for
    // the home dashboard. Returns null for the expected data gap between school years.
    // Backs the "Formations" submenu and the UFA liste (19a) - one entry per Program actually
    // carrying the establishment's single alternance Modality (Modality::$isAlternance), for the
    // selected SchoolYear only, same "one nav entry per active alternance Program" grouping the
    // design doc describes.
    //
    // $testProgram is the "Données de test" box of the Alternances dashboard (33a/33b): null keeps
    // both worlds (every other caller), true/false narrows to one of them - a strict either/or,
    // matching InternshipTutorLinkRepository::findForDashboard()'s own $testData. Ignored for a
    // test VIEWER, whose world is already all-test and for whom "hide the test formations" would
    // just empty the screen.
    /** @return list<Program> */
    public function findAlternanceForSchoolYear(SchoolYear $schoolYear, bool $includeInactive = false, ?User $viewer = null, ?bool $testProgram = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.modalities', 'm')
            ->addSelect('m')
            ->leftJoin('p.cohort', 'c')->addSelect('c')
            ->where('p.schoolYear = :schoolYear')
            ->andWhere('m.isAlternance = true')
            ->setParameter('schoolYear', $schoolYear)
            ->orderBy('p.shortName', 'ASC');

        // Same asymmetry as everywhere else: a test account is confined to test Programs, a real
        // one is untouched. $viewer is optional so a caller with no user in hand (a command, a
        // webhook) keeps the unfiltered list rather than silently getting a real-account view.
        if ($viewer?->isTestUser()) {
            $qb->andWhere('p.testProgram = true');
        } elseif (null !== $testProgram) {
            $qb->andWhere('p.testProgram = :testProgram')->setParameter('testProgram', $testProgram);
        }

        if (!$includeInactive) {
            $qb->andWhere('p.inactiveDate IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Every active Program of one school year, students and modalities already hydrated.
     *
     * Written for the UFA contract import (App\Service\AlternanceImport\ImportAnalyzer), which has
     * to find a named student *anywhere* in the year before it can tell "unknown alternant" from
     * "enrolled, but in a formation that isn't in alternance" - a distinction
     * findAlternanceForSchoolYear() cannot make, since it only ever returns the second kind.
     *
     * @return list<Program>
     */
    public function findAllActiveForSchoolYear(SchoolYear $schoolYear): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.cohort', 'c')->addSelect('c')
            ->leftJoin('p.students', 's')->addSelect('s')
            ->leftJoin('p.modalities', 'm')->addSelect('m')
            ->where('p.schoolYear = :schoolYear')
            ->andWhere('p.inactiveDate IS NULL')
            ->setParameter('schoolYear', $schoolYear)
            ->orderBy('p.shortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForStudent(User $student): ?Program
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.students', 's')
            ->where('s = :student')
            ->andWhere('p.inactiveDate IS NULL')
            // A test account only ever sees test Programs; for a real one this is a no-op, so
            // enrolment in a test Program keeps behaving as before - see
            // App\Security\StructureAccessChecker::matchesTestMode().
            ->andWhere(':testMode = false OR p.testProgram = true')
            ->setParameter('student', $student)
            ->setParameter('testMode', $student->isTestUser())
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // The rare "deux formations" case (design_handoff_dashboards etu-e): usually one entry, but a
    // student simultaneously enrolled in two active Programs (e.g. BTS + Bachelor en alternance)
    // gets both - the dashboard shows a chip/filter per formation. Same active-rows-only rule as
    // findActiveForStudent() above, which stays around for the callers that want "the" single
    // most recent one (mobile API).
    /** @return list<Program> */
    public function findAllActiveForStudent(User $student): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c')
            ->leftJoin('p.cohort', 'c')
            ->innerJoin('p.students', 's')
            ->where('s = :student')
            ->andWhere('p.inactiveDate IS NULL')
            ->andWhere(':testMode = false OR p.testProgram = true')
            ->setParameter('student', $student)
            ->setParameter('testMode', $student->isTestUser())
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
