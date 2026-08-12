<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\VideoResourceFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoResourceFile>
 */
class VideoResourceFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoResourceFile::class);
    }

    /**
     * How far into its files a student is on each of these videos, taken as the lowest of them -
     * the video side of AudioRecordingFileRepository::findLowestPercentByRecordingIdForStudent(),
     * and the same reading for the same reason: a condition asking for 100 % means every file
     * watched through, and an average would let a long one carry a skipped one.
     *
     * @param list<int> $resourceIds
     *
     * @return array<int, int> video resource id => lowest percentage across its files
     */
    public function findLowestPercentByResourceIdForStudent(array $resourceIds, User $student): array
    {
        if ([] === $resourceIds) {
            return [];
        }

        /** @var list<array{resourceId: int|string, percent: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.resource) AS resourceId', 'p.maxWatchedPercent AS percent')
            ->leftJoin(
                'App\Entity\VideoWatchProgress',
                'p',
                'WITH',
                'p.file = f AND p.student = :student',
            )
            ->where('f.resource IN (:resources)')
            ->setParameter('resources', $resourceIds)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        $lowest = [];
        foreach ($rows as $row) {
            $resourceId = (int) $row['resourceId'];
            $percent = (int) $row['percent'];
            $lowest[$resourceId] = min($lowest[$resourceId] ?? 100, $percent);
        }

        return $lowest;
    }
}
