<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EngagementDeclaration;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\EngagementKind;
use App\Enum\EngagementState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EngagementDeclaration>
 */
class EngagementDeclarationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EngagementDeclaration::class);
    }

    /**
     * The validation queue of one class, waiting first.
     *
     * **The order is a CASE in a HIDDEN alias, never `ORDER BY d.state`.** Sorting on an enum column
     * sorts the stored values, and `filed` would come after `refused` - the trap this repository
     * already paid for once, on the quiz library's folders. Refused declarations stay in the list,
     * struck through, so that nothing gets re-filed three times in the hope of another reviewer.
     *
     * @return list<EngagementDeclaration>
     */
    public function queueFor(Program $program): array
    {
        /** @var list<EngagementDeclaration> $rows */
        $rows = $this->createQueryBuilder('d')
            ->addSelect("CASE d.state WHEN 'filed' THEN 1 WHEN 'validated' THEN 2 ELSE 3 END AS HIDDEN state_rank")
            ->where('d.program = :program')
            ->orderBy('state_rank', 'ASC')
            ->addOrderBy('d.createdAt', 'DESC')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * One student's own declarations for a period, most recent first.
     *
     * @return list<EngagementDeclaration>
     */
    public function findForStudent(User $student, Program $program): array
    {
        /** @var list<EngagementDeclaration> $rows */
        $rows = $this->createQueryBuilder('d')
            ->where('d.student = :student')
            ->andWhere('d.program = :program')
            ->orderBy('d.createdAt', 'DESC')
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countWaiting(Program $program): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.program = :program')
            ->andWhere('d.state = :state')
            ->setParameter('program', $program)
            ->setParameter('state', EngagementState::Filed)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether this student already declared a mandate in this formation - the one kind that is
     * once-only.
     *
     * Once per **formation** rather than once per period since 2026-08-28: a mandate is held for a
     * year, not for a term, and asking which period we were in before answering was a condition with
     * nothing behind it.
     */
    public function hasKindInProgram(User $student, Program $program, EngagementKind $kind): bool
    {
        return null !== $this->createQueryBuilder('d')
            ->select('d.id')
            ->where('d.student = :student')
            ->andWhere('d.program = :program')
            ->andWhere('d.kind = :kind')
            ->andWhere('d.state != :refused')
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->setParameter('kind', $kind)
            ->setParameter('refused', EngagementState::Refused)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
