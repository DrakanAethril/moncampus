<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttendanceStatement;
use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AttendanceStatement>
 */
class AttendanceStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceStatement::class);
    }

    /**
     * Every statement of one period, oldest first - the order the streak is counted in, so it is
     * the order this method promises rather than something each caller re-sorts.
     *
     * @return list<AttendanceStatement>
     */
    public function findForPeriod(Program $program, EvaluationPeriod $period): array
    {
        /** @var list<AttendanceStatement> $statements */
        $statements = $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->andWhere('s.period = :period')
            ->orderBy('s.startsOn', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();

        return $statements;
    }

    public function findOneCovering(Program $program, EvaluationPeriod $period, \DateTimeImmutable $startsOn): ?AttendanceStatement
    {
        return $this->findOneBy([
            'program' => $program,
            'period' => $period,
            'startsOn' => $startsOn->setTime(0, 0),
        ]);
    }

    /** Whether this formation ever stated anything on this period - what makes the family exist. */
    public function hasAny(Program $program, EvaluationPeriod $period): bool
    {
        return [] !== $this->findForPeriod($program, $period);
    }
}
