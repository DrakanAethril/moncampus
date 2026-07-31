<?php

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

    // Powers the ROLE_TUTOR tutor landing page: matches an already-linked tutor (tutor =
    // $user, set once auto-linked), a not-yet-linked row whose free-text tutorEmail matches this
    // user's own email, or a not-yet-linked row whose spawned LdapManageUser request finished
    // with a login matching the username just used to authenticate - the caller opportunistically
    // sets tutor in all these cases (see InternshipTutorEvaluationController) since the LDAP
    // account didn't exist when the link was first created.
    /** @return list<InternshipTutorLink> */
    public function findActiveForTutorUser(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('st', 'p')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.program', 'p')
            ->leftJoin('l.ldapManageUser', 'lmu')
            ->where('l.inactiveDate IS NULL')
            ->andWhere('l.tutor = :user OR (l.tutor IS NULL AND l.tutorEmail = :email) OR (l.tutor IS NULL AND lmu.login = :username)')
            ->setParameter('user', $user)
            ->setParameter('email', $user->getEmail())
            ->setParameter('username', $user->getUsername())
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

    // Powers InternshipTutorProvisioningService: finds the most recent other link for the same
    // tutor (matched by free-text email, the only identifier known before the tutor has an
    // account) to reuse its resolved User or already-queued LdapManageUser instead of requesting
    // a duplicate account.
    public function findOneMostRecentByTutorEmail(string $email, ?InternshipTutorLink $excluding = null): ?InternshipTutorLink
    {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('LOWER(l.tutorEmail) = LOWER(:email)')
            ->setParameter('email', $email)
            ->orderBy('l.creationDate', 'DESC')
            ->setMaxResults(1);

        if (null !== $excluding?->getId()) {
            $qb->andWhere('l.id != :excludingId')->setParameter('excludingId', $excluding->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    // Powers 32a/32b's "Rechercher un tuteur existant" ajax field and 26b's Tuteurs annuaire -
    // there's no dedicated Tutor table (see the feature's plan doc, architecture call 0.1), so
    // "existing tutors" means "distinct tutorEmail values already seen across links", most recent
    // link per email wins for display purposes (name/phone/entreprise can drift across links for
    // the same person; the latest is the best guess).
    /** @return list<InternshipTutorLink> */
    public function searchDistinctTutors(string $query, int $limit, ?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('e')
            ->leftJoin('l.enterprise', 'e')
            ->where('l.id IN (
                SELECT MAX(l2.id) FROM App\Entity\InternshipTutorLink l2
                GROUP BY l2.tutorEmail
            )')
            ->orderBy('l.tutorLastName', 'ASC')
            ->addOrderBy('l.tutorFirstName', 'ASC')
            ->setMaxResults($limit);

        // Same asymmetry as everywhere else: a test account only ever gets tutors known through a
        // test alternance, a real one keeps the full directory - see
        // App\Security\StructureAccessChecker::matchesTestMode().
        if ($viewer?->isTestUser()) {
            $qb->andWhere('l.testAlternance = true');
        }

        if ('' !== $query) {
            $qb->andWhere('l.tutorFirstName LIKE :query OR l.tutorLastName LIKE :query OR l.tutorEmail LIKE :query OR e.name LIKE :query')
                ->setParameter('query', '%'.$query.'%');
        }

        return $qb->getQuery()->getResult();
    }

    // Powers 32a's "l'entreprise est reprise automatiquement" auto-carry once an existing tutor is
    // picked - reuses the same most-recent-link-wins convention as findOneMostRecentByTutorEmail().
    public function findMostRecentEnterpriseForTutorEmail(string $email): ?Enterprise
    {
        return $this->findOneMostRecentByTutorEmail($email)?->getEnterprise();
    }

    // Powers the Alternances dashboard (33a/33b) - deliberately unpaginated per the spec ("pas de
    // pagination"), filtering is client-side over the full result.
    /** @return list<InternshipTutorLink> */
    public function findForDashboard(Program $program, bool $includeInactive, ?Enterprise $enterprise = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->addSelect('st', 'tu', 'e', 'p')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.tutor', 'tu')
            ->leftJoin('l.enterprise', 'e')
            ->leftJoin('l.program', 'p')
            ->where('l.program = :program')
            ->setParameter('program', $program)
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
    public function countActiveForSchoolYear(SchoolYear $schoolYear): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->innerJoin('l.program', 'p')
            ->innerJoin('p.modalities', 'm')
            ->where('p.schoolYear = :schoolYear')
            ->andWhere('m.isAlternance = true')
            ->andWhere('l.inactiveDate IS NULL')
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
            ->setParameter('schoolYear', $schoolYear)
            ->setParameter('contractType', $contractType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    // Powers the "nb d'alternances actives" column on the Tuteurs annuaire (26b) - small scale
    // (one establishment's tutor roster), an N+1 count per row is fine here, same convention as
    // UserRepository's own role-matching-in-PHP comment.
    public function countActiveForTutorEmail(string $email): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('LOWER(l.tutorEmail) = LOWER(:email)')
            ->andWhere('l.inactiveDate IS NULL')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('l.tutorFirstName LIKE :search OR l.tutorLastName LIKE :search OR e.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }
}
