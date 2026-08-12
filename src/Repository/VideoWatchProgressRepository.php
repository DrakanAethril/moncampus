<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Entity\VideoWatchProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoWatchProgress>
 */
class VideoWatchProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoWatchProgress::class);
    }

    public function findOneFor(VideoResourceFile $file, User $student): ?VideoWatchProgress
    {
        return $this->findOneBy(['file' => $file, 'student' => $student]);
    }

    /**
     * One student's progress on every file of a resource, keyed by file id.
     *
     * One query per screen rather than one per file: a player page paints a bar for each video of
     * the set, and asking per file is exactly how that page becomes N+1.
     *
     * @return array<int, VideoWatchProgress>
     */
    public function findByFileIdForStudent(VideoResource $resource, User $student): array
    {
        /** @var list<VideoWatchProgress> $rows */
        $rows = $this->createQueryBuilder('p')
            ->join('p.file', 'f')
            ->where('f.resource = :resource')
            ->andWhere('p.student = :student')
            ->setParameter('resource', $resource)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $byFileId = [];
        foreach ($rows as $row) {
            $byFileId[(int) $row->getFile()?->getId()] = $row;
        }

        return $byFileId;
    }
}
