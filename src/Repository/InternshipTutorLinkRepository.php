<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\ContractTypeCode;
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
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->leftJoin('l.enterprise', 'e')
            ->leftJoin('l.tutor', 'tu')
            ->where('l.program = :program')
            ->setParameter('program', $program);
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
            ->leftJoin('l.enterprise', 'e')->addSelect('e')
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

    // Powers the ROLE_TUTOR tutor landing page. A plain "this user is the tutor" match now that
    // the account exists from the moment the link is created (see
    // App\Service\InternshipTutorProvisioningService) - this used to also try a free-text e-mail
    // match and a match on the login the LDAP consumer script generated, because $tutor stayed
    // null until the tutor's very first login.
    /** @return list<InternshipTutorLink> */
    public function findActiveForTutorUser(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('st', 'p')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.program', 'p')
            ->where('l.inactiveDate IS NULL')
            ->andWhere('l.tutor = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    // Powers the student's own "view my booklet" link, which only knows "this program, me" - not
    // a tutorLink id.
    public function findOneForStudentAndProgram(User $student, Program $program): ?InternshipTutorLink
    {
        return $this->findOneBy(['student' => $student, 'program' => $program, 'inactiveDate' => null]);
    }

    // Powers the evaluation-reminder action - every active link is a candidate tutor to check
    // for a missing InternshipTutorEvaluation on the chosen period.
    /** @return list<InternshipTutorLink> */
    public function findAllActiveForProgram(Program $program): array
    {
        return $this->findBy(['program' => $program, 'inactiveDate' => null]);
    }

    /**
     * The students of one Program whose alternance is over and who have no live one left - the
     * people an evaluation relance must no longer reach (see
     * Program\InternshipReminderController::findPendingEvaluations(), which lists pending students
     * from the Program's roster and so cannot see terminations by itself).
     *
     * Keyed rather than returned as a list so callers can test membership without a second loop.
     *
     * @return array<int, true> student id => every alternance of theirs on this Program is terminated
     */
    public function findTerminatedStudentIdsForProgram(Program $program): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.student) AS studentId', 'COUNT(l.id) AS total', 'SUM(CASE WHEN l.inactiveDate IS NULL THEN 1 ELSE 0 END) AS live')
            ->where('l.program = :program')
            ->setParameter('program', $program)
            ->groupBy('studentId')
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($rows as $row) {
            if ((int) $row['total'] > 0 && 0 === (int) $row['live']) {
                $ids[(int) $row['studentId']] = true;
            }
        }

        return $ids;
    }

    // Powers 32a/32b's "Rechercher un tuteur existant" ajax field and 26b's Tuteurs annuaire.
    // One row per tutor account, keeping that tutor's most recent link so the entreprise shown
    // beside their name is the one they're currently at - the employer can drift from one
    // alternance to the next, the latest is the best guess. This used to group by free-text
    // e-mail for want of anything better; the tutor User is now that identifier.
    /** @return list<InternshipTutorLink> */
    public function searchDistinctTutors(string $query, int $limit, ?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('e', 'tu')
            ->leftJoin('l.enterprise', 'e')
            ->innerJoin('l.tutor', 'tu')
            ->where('l.id IN (
                SELECT MAX(l2.id) FROM App\Entity\InternshipTutorLink l2
                GROUP BY l2.tutor
            )')
            ->orderBy('tu.lastname', 'ASC')
            ->addOrderBy('tu.firstname', 'ASC')
            ->setMaxResults($limit);

        // Same asymmetry as everywhere else: a test account only ever gets tutors known through a
        // test alternance, a real one keeps the full directory - see
        // App\Security\StructureAccessChecker::matchesTestMode().
        if ($viewer?->isTestUser()) {
            $qb->andWhere('l.testAlternance = true');
        }

        if ('' !== $query) {
            $qb->andWhere('tu.firstname LIKE :query OR tu.lastname LIKE :query OR tu.contactEmail LIKE :query OR e.name LIKE :query')
                ->setParameter('query', '%'.$query.'%');
        }

        return $qb->getQuery()->getResult();
    }

    // Powers 32a's "l'entreprise est reprise automatiquement" auto-carry once an existing tutor is
    // picked - same most-recent-link-wins convention as searchDistinctTutors() above.
    public function findMostRecentEnterpriseForTutor(User $tutor): ?Enterprise
    {
        /** @var ?InternshipTutorLink $link */
        $link = $this->createQueryBuilder('l')
            ->where('l.tutor = :tutor')
            ->setParameter('tutor', $tutor)
            ->orderBy('l.creationDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $link?->getEnterprise();
    }

    /**
     * Same "most recent link wins" answer as findMostRecentEnterpriseForTutor() above, for a whole
     * page of tutors at once - the tutor picker's ajax results (see
     * App\Controller\Ufa\AlternanceController::tutorSearch()) now come from the user directory
     * rather than from links, so the entreprise shown beside each name is looked up here instead
     * of arriving with the row, and doing it one query per result would be an N+1.
     *
     * Tutors with no alternance yet are simply absent from the map, not mapped to null.
     *
     * @param list<User> $tutors
     *
     * @return array<int, Enterprise> keyed by tutor id
     */
    public function findMostRecentEnterprisesForTutors(array $tutors): array
    {
        if ([] === $tutors) {
            return [];
        }

        $links = $this->createQueryBuilder('l')
            ->addSelect('e', 'tu')
            ->innerJoin('l.tutor', 'tu')
            ->leftJoin('l.enterprise', 'e')
            ->where('l.tutor IN (:tutors)')
            ->setParameter('tutors', $tutors)
            // Ascending, so the most recent link is the last one to write its entreprise into the
            // map below - the cheap way to get "latest wins" without a per-tutor subquery.
            ->orderBy('l.creationDate', 'ASC')
            ->getQuery()
            ->getResult();

        $enterprises = [];
        foreach ($links as $link) {
            $enterprise = $link->getEnterprise();
            $tutorId = $link->getTutor()?->getId();
            if (null !== $enterprise && null !== $tutorId) {
                $enterprises[$tutorId] = $enterprise;
            }
        }

        return $enterprises;
    }

    // Powers the Alternances dashboard (33a/33b), one page at a time. The spec said "pas de
    // pagination"; that held while the list showed one formation, and stopped holding once
    // « Toutes les formations » became its default - the whole year at once is several hundred rows,
    // each asking the status resolver for its current step.
    //
    // $programs is the formation picker's own list (or the one formation picked in it): an empty one
    // matches nothing, never everything, so a viewer with no formation on offer sees an empty list
    // rather than every alternance of the establishment.
    //
    // $testData is a strict either/or, not an "include as well": the dashboard's "Données de test"
    // box swaps the list from the real world to the fake one, the same way a test account swaps
    // worlds everywhere else (see App\Security\StructureAccessChecker::matchesTestMode()). Showing
    // both at once is what the flag exists to prevent.
    /**
     * @param list<Program> $programs
     *
     * @return list<InternshipTutorLink>
     */
    public function findDashboardPage(array $programs, bool $includeInactive, ?Enterprise $enterprise, ?string $search, bool $testData, int $offset, int $limit): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->dashboardQuery($programs, $includeInactive, $enterprise, $search, $testData)
            ->addSelect('st', 'tu', 'e', 'p')
            ->orderBy('st.lastname', 'ASC')
            ->addOrderBy('st.firstname', 'ASC')
            // Two homonyms would otherwise swap places between two page requests and one of them
            // could show on both pages while the other showed on neither.
            ->addOrderBy('l.id', 'ASC')
            // Every join above is to-one, so the limit counts alternances, not joined rows - no
            // Doctrine Paginator needed.
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @param list<Program> $programs */
    public function countForDashboard(array $programs, bool $includeInactive, ?Enterprise $enterprise, ?string $search, bool $testData): int
    {
        if ([] === $programs) {
            return 0;
        }

        return (int) $this->dashboardQuery($programs, $includeInactive, $enterprise, $search, $testData)
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<Program> $programs */
    private function dashboardQuery(array $programs, bool $includeInactive, ?Enterprise $enterprise, ?string $search, bool $testData): QueryBuilder
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.tutor', 'tu')
            ->leftJoin('l.enterprise', 'e')
            ->leftJoin('l.program', 'p')
            ->where('l.program IN (:programs)')
            ->andWhere('l.testAlternance = :testData')
            ->setParameter('programs', $programs)
            ->setParameter('testData', $testData);

        if (null !== $enterprise) {
            $qb->andWhere('l.enterprise = :enterprise')->setParameter('enterprise', $enterprise);
        }

        $this->applySearch($qb, $search, true);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb;
    }

    // Feeds the "Alternances" KPI card - all active alternance links for every alternance Program
    // of the given SchoolYear, regardless of which Program is currently filtered on the dashboard.
    // Test alternances are excluded from every KPI card here and below, unconditionally and with no
    // toggle: the cards are the establishment's real headline figures, and a fake alternance
    // created to rehearse the signature flow would quietly inflate them. The list underneath has
    // its own "Données de test" switch - the KPIs deliberately don't follow it.
    public function countActiveForSchoolYear(SchoolYear $schoolYear): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->innerJoin('l.program', 'p')
            ->innerJoin('p.modalities', 'm')
            ->where('p.schoolYear = :schoolYear')
            ->andWhere('m.isAlternance = true')
            ->andWhere('l.inactiveDate IS NULL')
            ->andWhere('l.testAlternance = false')
            ->andWhere('p.testProgram = false')
            ->setParameter('schoolYear', $schoolYear)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // Feeds the "Contrats d'apprentissage"/"Contrats de professionnalisation" KPI cards.
    public function countActiveForSchoolYearAndContractType(SchoolYear $schoolYear, ContractTypeCode $contractType): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->innerJoin('l.program', 'p')
            ->innerJoin('p.modalities', 'm')
            ->where('p.schoolYear = :schoolYear')
            ->andWhere('m.isAlternance = true')
            ->andWhere('l.inactiveDate IS NULL')
            ->andWhere('l.contractType = :contractType')
            ->andWhere('l.testAlternance = false')
            ->andWhere('p.testProgram = false')
            ->setParameter('schoolYear', $schoolYear)
            ->setParameter('contractType', $contractType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * « Alternances en cours » for one page of the UFA's Entreprises list, as a map id => count.
     *
     * Asked for the whole page at once rather than employer by employer: the list is 20 rows and a
     * count per row would be 20 queries. An employer with none at all is simply absent from the map
     * - the caller reads it with a `?? 0`, which is also what a page containing no employer answers.
     *
     * "En cours" is the same fact as everywhere else in the UFA: the alternance has not been
     * terminated (InternshipTutorLink::$inactiveDate). It is deliberately not "today is between the
     * two contract dates" - a contract that ran out while the livret is still being signed has not
     * stopped asking anything of anyone, and the dashboard counts it too.
     *
     * @param list<Enterprise> $enterprises
     *
     * @return array<int, int>
     */
    public function countActiveByEnterprise(array $enterprises): array
    {
        if ([] === $enterprises) {
            return [];
        }

        /** @var list<array{id: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.enterprise) AS id', 'COUNT(l.id) AS total')
            ->where('l.enterprise IN (:enterprises)')
            ->andWhere('l.inactiveDate IS NULL')
            ->groupBy('l.enterprise')
            ->setParameter('enterprises', $enterprises)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Every alternance this employer has ever carried, terminated ones included - the fiche's
     * contacts are built from it (App\Service\EnterpriseContacts).
     *
     * Terminated links are kept in on purpose: a tutor whose alternance ended is still this
     * company's contact, and dropping them would empty the fiche of an employer between two
     * contracts. Which ones are still running is said row by row instead, and they come first -
     * MySQL sorts NULL before anything else on an ASC order, which is what the first ORDER BY
     * clause is after.
     *
     * @return list<InternshipTutorLink>
     */
    public function findAllForEnterprise(Enterprise $enterprise): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('st', 'tu', 'p')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.tutor', 'tu')
            ->leftJoin('l.program', 'p')
            ->where('l.enterprise = :enterprise')
            ->setParameter('enterprise', $enterprise)
            ->orderBy('l.inactiveDate', 'ASC')
            ->addOrderBy('l.contractStartDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Callers all join l.tutor as "tu" and l.enterprise as "e" before reaching here, and l.student
    // as "st" when they ask for $includeStudent. The student is matched on the full name as it is
    // typed - either way round, since the dashboard prints "Prénom Nom" but a list is sorted by
    // surname - and on the login, which is what the row shows for an account LDAP gave no name.
    private function applySearch(QueryBuilder $qb, ?string $search, bool $includeStudent = false): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $conditions = ['tu.firstname LIKE :search', 'tu.lastname LIKE :search', 'e.name LIKE :search'];
        if ($includeStudent) {
            array_push(
                $conditions,
                "CONCAT(COALESCE(st.firstname, ''), ' ', COALESCE(st.lastname, '')) LIKE :search",
                "CONCAT(COALESCE(st.lastname, ''), ' ', COALESCE(st.firstname, '')) LIKE :search",
                'st.username LIKE :search',
            );
        }

        $qb->andWhere(implode(' OR ', $conditions))
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }
}
