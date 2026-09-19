<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VideoResource;
use App\Entity\VideoWatchEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoWatchEvent>
 */
class VideoWatchEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoWatchEvent::class);
    }

    /**
     * Every event of a resource's files, grouped by student then by file, oldest first - what the
     * statistics screen unfolds under each student. One query for the whole class.
     *
     * @return array<int, array<int, list<VideoWatchEvent>>> student id => file id => events
     */
    public function findByStudentAndFileForResource(VideoResource $resource): array
    {
        /** @var list<VideoWatchEvent> $rows */
        $rows = $this->createQueryBuilder('e')
            ->addSelect('p')
            ->innerJoin('e.progress', 'p')
            ->innerJoin('p.file', 'f')
            ->where('f.resource = :resource')
            ->setParameter('resource', $resource)
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $event) {
            $progress = $event->getProgress();
            $grouped[(int) $progress?->getStudent()?->getId()][(int) $progress?->getFile()?->getId()][] = $event;
        }

        return $grouped;
    }
}
