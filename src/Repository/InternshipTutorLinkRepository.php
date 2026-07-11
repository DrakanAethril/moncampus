<?php

namespace App\Repository;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
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

    // Powers the ROLE_EXTERNAL tutor landing page: matches an already-linked tutor (tutor =
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
