<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VideoCuePoint;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoCuePoint>
 */
class VideoCuePointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoCuePoint::class);
    }

    /**
     * The markers of one file, in playing order, with the question they ask joined in: every caller
     * reads the statement right after the timecode, and a marker per query would be an N+1 on the
     * screen that draws the whole timeline.
     *
     * @return list<VideoCuePoint>
     */
    public function findForFile(VideoResourceFile $file): array
    {
        /** @var list<VideoCuePoint> $cuePoints */
        $cuePoints = $this->createQueryBuilder('c')
            ->addSelect('q')
            ->join('c.question', 'q')
            ->andWhere('c.file = :file')
            ->setParameter('file', $file)
            ->orderBy('c.timecodeSeconds', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $cuePoints;
    }

    /**
     * Every marker of a video set, file by file then in playing order - the teacher's results
     * screen reads the whole video at once.
     *
     * @return list<VideoCuePoint>
     */
    public function findForResource(VideoResource $resource): array
    {
        /** @var list<VideoCuePoint> $cuePoints */
        $cuePoints = $this->createQueryBuilder('c')
            ->addSelect('q', 'f')
            ->join('c.question', 'q')
            ->join('c.file', 'f')
            ->andWhere('f.resource = :resource')
            ->setParameter('resource', $resource)
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('c.timecodeSeconds', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $cuePoints;
    }

    /**
     * How many markers each file of a set carries, for the list and the step-2 screen.
     *
     * @return array<int, int> file id => number of markers
     */
    public function countByFileIdForResource(VideoResource $resource): array
    {
        /** @var list<array{fileId: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.file) AS fileId', 'COUNT(c.id) AS total')
            ->join('c.file', 'f')
            ->andWhere('f.resource = :resource')
            ->setParameter('resource', $resource)
            ->groupBy('c.file')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['fileId']] = (int) $row['total'];
        }

        return $counts;
    }
}
