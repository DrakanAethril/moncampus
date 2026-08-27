<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EvaluationPeriod;
use App\Entity\GameEntry;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameEntry>
 */
class GameEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameEntry::class);
    }

    /**
     * The points of one student on one period, family by family - the numerator of the index.
     *
     * A plain sum of the ledger, reversals included: an inverse line is a negative line, so undoing
     * a gesture needs no special case here. Families with no line at all are absent from the result
     * rather than zero, which is not the same thing as an empty family - the denominator answers
     * that, in App\Service\Game\GamePossibleResolver.
     *
     * @return array<string, int> keyed by App\Enum\GameFamily value
     */
    public function sumByFamily(User $student, Program $program, EvaluationPeriod $period): array
    {
        /** @var list<array{family: string, total: string|int|null}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.family AS family, SUM(e.points) AS total')
            ->where('e.student = :student')
            ->andWhere('e.program = :program')
            ->andWhere('e.period = :period')
            ->groupBy('e.family')
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['family']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * The same reading for a whole class in one query - what the ranking and the closure are drawn
     * from, rather than one query per student.
     *
     * @param list<User> $students
     *
     * @return array<int, array<string, int>> student id => family value => points
     */
    public function sumByFamilyForStudents(array $students, Program $program, EvaluationPeriod $period): array
    {
        if ([] === $students) {
            return [];
        }

        /** @var list<array{student: int, family: string, total: string|int|null}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.student) AS student, e.family AS family, SUM(e.points) AS total')
            ->where('e.student IN (:students)')
            ->andWhere('e.program = :program')
            ->andWhere('e.period = :period')
            ->groupBy('e.student, e.family')
            ->setParameter('students', $students)
            ->setParameter('program', $program)
            ->setParameter('period', $period)
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['student']][(string) $row['family']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * Whether this exact line already exists - the guard that makes a re-read idempotent.
     *
     * Keyed on the source rather than on the date: the same submission read twice must not pay
     * twice, however many times the collector runs.
     */
    public function existsForSource(User $student, string $sourceType, int $sourceId, string $ruleCode): bool
    {
        return null !== $this->createQueryBuilder('e')
            ->select('e.id')
            ->where('e.student = :student')
            ->andWhere('e.sourceType = :sourceType')
            ->andWhere('e.sourceId = :sourceId')
            ->andWhere('e.ruleCode = :ruleCode')
            ->setParameter('student', $student)
            ->setParameter('sourceType', $sourceType)
            ->setParameter('sourceId', $sourceId)
            ->setParameter('ruleCode', $ruleCode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * How many lines of one rule were written for this student inside one week - what the weekly
     * caps of §5.3 are counted against.
     */
    public function countInWeek(User $student, string $ruleCode, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.student = :student')
            ->andWhere('e.ruleCode = :ruleCode')
            ->andWhere('e.occurredAt >= :start')
            ->andWhere('e.occurredAt < :end')
            ->setParameter('student', $student)
            ->setParameter('ruleCode', $ruleCode)
            ->setParameter('start', $weekStart)
            ->setParameter('end', $weekEnd)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The journal, most recent first - screen 1's « Ce qui a compté cette semaine » and its full
     * listing behind the same query.
     *
     * @return list<GameEntry>
     */
    public function journal(User $student, Program $program, EvaluationPeriod $period, ?int $limit = null): array
    {
        $query = $this->createQueryBuilder('e')
            ->where('e.student = :student')
            ->andWhere('e.program = :program')
            ->andWhere('e.period = :period')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('student', $student)
            ->setParameter('program', $program)
            ->setParameter('period', $period);

        if (null !== $limit) {
            $query->setMaxResults($limit);
        }

        /** @var list<GameEntry> $entries */
        $entries = $query->getQuery()->getResult();

        return $entries;
    }
}
