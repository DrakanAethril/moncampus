<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameStatementLine;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameStatementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameStatementLine>
 */
class GameStatementLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameStatementLine::class);
    }

    /**
     * One student's attendance lines across a formation, in the order the relevés cover - which is
     * the order the streak has to be counted in.
     *
     * @return list<GameStatementLine>
     */
    public function attendanceForStudent(User $student, Program $program): array
    {
        /** @var list<GameStatementLine> $lines */
        $lines = $this->createQueryBuilder('l')
            ->addSelect('s')
            ->join('l.statement', 's')
            ->where('l.student = :student')
            ->andWhere('s.program = :program')
            ->andWhere('s.type = :type')
            ->orderBy('s.startsOn', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->setParameter('type', GameStatementType::Attendance)
            ->getQuery()
            ->getResult();

        return $lines;
    }

    /**
     * One student's council mentions across their whole cursus, best mention first.
     *
     * **The order is a CASE in a HIDDEN alias, never `ORDER BY l.mention`.** Sorting on an enum
     * column sorts the stored values, so `compliments` would come out ahead of `excellence` - the
     * trap this repository already paid for once, on the quiz library's folders.
     *
     * @return list<GameStatementLine>
     */
    public function councilMentionsForStudent(User $student): array
    {
        /** @var list<GameStatementLine> $lines */
        $lines = $this->createQueryBuilder('l')
            ->addSelect('s')
            ->addSelect(
                'CASE l.mention'
                ." WHEN 'excellence' THEN 1"
                ." WHEN 'congratulations' THEN 2"
                ." WHEN 'compliments' THEN 3"
                ." WHEN 'encouragements' THEN 4"
                ." WHEN 'none' THEN 5"
                .' ELSE 6 END AS HIDDEN mention_rank'
            )
            ->join('l.statement', 's')
            ->where('l.student = :student')
            ->andWhere('s.type = :type')
            ->andWhere('l.mention IS NOT NULL')
            ->orderBy('mention_rank', 'ASC')
            ->setParameter('student', $student)
            ->setParameter('type', GameStatementType::Council)
            ->getQuery()
            ->getResult();

        return $lines;
    }
}
