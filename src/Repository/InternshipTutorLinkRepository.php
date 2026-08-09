<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipStudentEvaluation;
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

    // Staff dashboard banner (design_handoff_dashboards staff-a): tutors who still haven't
    // signed their evaluation for this period - only links whose periods are actually open
    // (engagement signed by the centre) count, an unsigned engagement means the tutor isn't
    // "late", the whole livret just isn't started.
    public function countPendingTutorForPeriod(InternshipEvaluationPeriod $period): int
    {
        return (int) $this->pendingForPeriodQueryBuilder($period)
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s tev WHERE tev.tutorLink = l AND tev.evaluationPeriod = :period AND tev.signedAt IS NOT NULL)',
                InternshipTutorEvaluation::class,
            ))
            ->getQuery()
            ->getSingleScalarResult();
    }

    // Same banner: alternants whose turn is open (tutor signed) but who haven't signed their own
    // evaluation yet.
    public function countPendingStudentForPeriod(InternshipEvaluationPeriod $period): int
    {
        return (int) $this->pendingForPeriodQueryBuilder($period)
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s tev WHERE tev.tutorLink = l AND tev.evaluationPeriod = :period AND tev.signedAt IS NOT NULL)',
                InternshipTutorEvaluation::class,
            ))
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s sev WHERE sev.student = l.student AND sev.evaluationPeriod = :period AND sev.signedAt IS NOT NULL)',
                InternshipStudentEvaluation::class,
            ))
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function pendingForPeriodQueryBuilder(InternshipEvaluationPeriod $period): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.program = :program')
            ->andWhere('l.inactiveDate IS NULL')
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s eng WHERE eng.tutorLink = l AND eng.signedCenterAt IS NOT NULL)',
                InternshipLivretEngagement::class,
            ))
            ->setParameter('program', $period->getProgram())
            ->setParameter('period', $period);
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
        return $this->createQueryBuilder('l')
            ->where('l.tutor = :tutor')
            ->setParameter('tutor', $tutor)
            ->orderBy('l.creationDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()?->getEnterprise();
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

    // Powers the Alternances dashboard (33a/33b) - deliberately unpaginated per the spec ("pas de
    // pagination"), filtering is client-side over the full result.
    //
    // $testData is a strict either/or, not an "include as well": the dashboard's "Données de test"
    // box swaps the list from the real world to the fake one, the same way a test account swaps
    // worlds everywhere else (see App\Security\StructureAccessChecker::matchesTestMode()). Showing
    // both at once is what the flag exists to prevent.
    /** @return list<InternshipTutorLink> */
    public function findForDashboard(Program $program, bool $includeInactive, ?Enterprise $enterprise = null, ?string $search = null, bool $testData = false): array
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('st', 'tu', 'e', 'p')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.tutor', 'tu')
            ->leftJoin('l.enterprise', 'e')
            ->leftJoin('l.program', 'p')
            ->where('l.program = :program')
            ->andWhere('l.testAlternance = :testData')
            ->setParameter('program', $program)
            ->setParameter('testData', $testData)
            ->orderBy('st.lastname', 'ASC')
            ->addOrderBy('st.firstname', 'ASC');

        if (null !== $enterprise) {
            $qb->andWhere('l.enterprise = :enterprise')->setParameter('enterprise', $enterprise);
        }

        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
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

    // Callers all join l.tutor as "tu" and l.enterprise as "e" before reaching here.
    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('tu.firstname LIKE :search OR tu.lastname LIKE :search OR e.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }
}
