<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EcoCourse;
use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use App\Enum\EcoCourseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EcoPositionPing>
 */
class EcoPositionPingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EcoPositionPing::class);
    }

    /** @return list<EcoPositionPing> */
    public function findForRunner(EcoRunner $runner): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.runner = :runner')
            ->setParameter('runner', $runner)
            ->orderBy('p.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The closed course that has waited longest with fixes nobody asked the IGN about - once a
     * race is over, and only then: a live race would have its area fetched again every pass.
     */
    public function findCourseIdWithUnresolvedTerrain(): ?int
    {
        $row = $this->createQueryBuilder('p')
            ->select('IDENTITY(r.course) AS courseId')
            ->join('p.runner', 'r')
            ->join('r.course', 'c')
            ->where('p.terrainResolved = false')
            ->andWhere('c.status = :closed')
            ->setParameter('closed', EcoCourseStatus::Closed)
            ->orderBy('c.closedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $courseId = \is_array($row) ? ($row['courseId'] ?? null) : null;

        return is_numeric($courseId) ? (int) $courseId : null;
    }

    /** @return list<EcoPositionPing> */
    public function findUnresolvedTerrainForCourse(EcoCourse $course, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.runner', 'r')
            ->where('r.course = :course')
            ->andWhere('p.terrainResolved = false')
            ->setParameter('course', $course)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
