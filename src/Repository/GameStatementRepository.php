<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameStatement;
use App\Entity\Program;
use App\Enum\GameStatementType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameStatement>
 */
class GameStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameStatement::class);
    }

    /**
     * A formation's relevés, most recent first, optionally of one kind.
     *
     * There is no period filter and that is the change of 2026-08-27: a team holds as many or as few
     * relevés as it wants, and the calendar decides only where their points land.
     *
     * @return list<GameStatement>
     */
    public function findForProgram(Program $program, ?GameStatementType $type = null): array
    {
        $query = $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->orderBy('s.heldOn', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setParameter('program', $program);

        if (null !== $type) {
            $query->andWhere('s.type = :type')->setParameter('type', $type);
        }

        /** @var list<GameStatement> $statements */
        $statements = $query->getQuery()->getResult();

        return $statements;
    }

    /**
     * The attendance relevés covering a stretch of time, oldest first - the order the streak is
     * counted in, so it is the order this method promises rather than something each caller re-sorts.
     *
     * @return list<GameStatement>
     */
    public function attendanceInOrder(Program $program): array
    {
        /** @var list<GameStatement> $statements */
        $statements = $this->createQueryBuilder('s')
            ->where('s.program = :program')
            ->andWhere('s.type = :type')
            ->orderBy('s.startsOn', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setParameter('program', $program)
            ->setParameter('type', GameStatementType::Attendance)
            ->getQuery()
            ->getResult();

        return $statements;
    }

    /** Whether an attendance relevé already covers this exact span - what stops a double pass. */
    public function findAttendanceStartingOn(Program $program, \DateTimeImmutable $startsOn): ?GameStatement
    {
        return $this->findOneBy([
            'program' => $program,
            'type' => GameStatementType::Attendance,
            'startsOn' => $startsOn->setTime(0, 0),
        ]);
    }
}
