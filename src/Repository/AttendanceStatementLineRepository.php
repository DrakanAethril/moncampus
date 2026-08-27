<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AttendanceStatementLine;
use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AttendanceStatementLine>
 */
class AttendanceStatementLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceStatementLine::class);
    }

    /**
     * One student's answers over a whole period, in the order the statements cover - which is the
     * order the streak has to be counted in.
     *
     * @return list<AttendanceStatementLine>
     */
    public function findForStudentInPeriod(User $student, Program $program, EvaluationPeriod $period): array
    {
        /** @var list<AttendanceStatementLine> $lines */
        $lines = $this->createQueryBuilder('l')
            ->addSelect('s')
            ->join('l.statement', 's')
            ->where('l.student = :student')
            ->andWhere('s.program = :program')
            ->andWhere('s.period = :period')
            ->orderBy('s.startsOn', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();

        return $lines;
    }

    /**
     * Every student's answers on one period in a single query - the closure and the ranking read
     * thirty students at once, not one at a time.
     *
     * @return array<int, list<AttendanceStatementLine>> student id => lines, oldest statement first
     */
    public function findByStudentForPeriod(Program $program, EvaluationPeriod $period): array
    {
        /** @var list<AttendanceStatementLine> $lines */
        $lines = $this->createQueryBuilder('l')
            ->addSelect('s')
            ->join('l.statement', 's')
            ->where('s.program = :program')
            ->andWhere('s.period = :period')
            ->orderBy('s.startsOn', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();

        $byStudent = [];
        foreach ($lines as $line) {
            $byStudent[(int) $line->getStudent()->getId()][] = $line;
        }

        return $byStudent;
    }
}
