<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClassCouncilMention;
use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClassCouncilMention>
 */
class ClassCouncilMentionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassCouncilMention::class);
    }

    /**
     * Every mention of one council, keyed by student id.
     *
     * @return array<int, ClassCouncilMention>
     */
    public function findForPeriod(Program $program, EvaluationPeriod $period): array
    {
        /** @var list<ClassCouncilMention> $rows */
        $rows = $this->createQueryBuilder('m')
            ->where('m.program = :program')
            ->andWhere('m.period = :period')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();

        $byStudent = [];
        foreach ($rows as $row) {
            $byStudent[(int) $row->getStudent()->getId()] = $row;
        }

        return $byStudent;
    }

    public function findOneFor(User $student, EvaluationPeriod $period): ?ClassCouncilMention
    {
        return $this->findOneBy(['student' => $student, 'period' => $period]);
    }

    /**
     * One student's mentions across their whole cursus, best mention first.
     *
     * **The order is a CASE in a HIDDEN alias, never `ORDER BY m.mention`.** Sorting on an enum
     * column sorts the stored values, so `compliments` would come out ahead of `excellence` - the
     * trap this repository already paid for once, on the quiz library's folders.
     *
     * @return list<ClassCouncilMention>
     */
    public function findForStudentRanked(User $student): array
    {
        /** @var list<ClassCouncilMention> $rows */
        $rows = $this->createQueryBuilder('m')
            ->addSelect(
                'CASE m.mention'
                ." WHEN 'excellence' THEN 1"
                ." WHEN 'congratulations' THEN 2"
                ." WHEN 'compliments' THEN 3"
                ." WHEN 'encouragements' THEN 4"
                ." WHEN 'none' THEN 5"
                .' ELSE 6 END AS HIDDEN mention_rank'
            )
            ->where('m.student = :student')
            ->orderBy('mention_rank', 'ASC')
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** How many students of the council already carry a mention - screen 6's « 18 / 30 saisies ». */
    public function countStated(Program $program, EvaluationPeriod $period): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.program = :program')
            ->andWhere('m.period = :period')
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
