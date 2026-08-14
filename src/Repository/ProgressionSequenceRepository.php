<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSequence;
use App\Entity\SequenceInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgressionSequence>
 */
class ProgressionSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgressionSequence::class);
    }

    /** @return list<ProgressionSequence> */
    public function findOrderedForProgression(Progression $progression): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('si', 'se', 'pl', 'ls')
            ->innerJoin('s.sequenceInstance', 'si')
            ->leftJoin('s.seances', 'se')
            ->leftJoin('se.placements', 'pl')
            ->leftJoin('pl.lessonSession', 'ls')
            ->where('s.progression = :progression')
            ->setParameter('progression', $progression)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('se.position', 'ASC')
            ->addOrderBy('pl.partIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The SequenceInstance ids this class has already planned, whichever progression planned them.
     *
     * Deliberately not narrowed to one progression nor to one teacher: a séquence instantiated for a
     * class is planned once, so what makes it unavailable is that ANY progression carries it. Rooted
     * on the instance's own Program rather than on the progression's, the two being the same class
     * by construction and the join being one hop shorter.
     *
     * @return list<int>
     */
    public function findPlannedInstanceIdsForProgram(Program $program): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT si.id AS id')
            ->innerJoin('s.sequenceInstance', 'si')
            ->where('si.program = :program')
            ->setParameter('program', $program)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['id'], $rows);
    }

    /** Whether any progression at all - this class's or another's - already plans this séquence. */
    public function isInstancePlanned(SequenceInstance $instance): bool
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.sequenceInstance = :instance')
            ->setParameter('instance', $instance)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findNextPosition(Progression $progression): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.position)')
            ->where('s.progression = :progression')
            ->setParameter('progression', $progression)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
