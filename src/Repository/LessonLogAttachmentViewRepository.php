<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LessonLogAttachment;
use App\Entity\LessonLogAttachmentView;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LessonLogAttachmentView>
 */
class LessonLogAttachmentViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonLogAttachmentView::class);
    }

    public function findOneFor(LessonLogAttachment $attachment, User $student): ?LessonLogAttachmentView
    {
        return $this->findOneBy(['attachment' => $attachment, 'student' => $student]);
    }

    /**
     * How many distinct students opened each document.
     *
     * @param list<LessonLogAttachment> $attachments
     *
     * @return array<int, int> document identifier => number of students
     */
    public function countByAttachment(array $attachments): array
    {
        if ([] === $attachments) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.attachment) AS attachmentId', 'COUNT(v.id) AS total')
            ->where('v.attachment IN (:attachments)')
            ->groupBy('v.attachment')
            ->setParameter('attachments', $attachments)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['attachmentId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * How many students opened ALL the given documents - the overall statistic of a séance: having
     * opened three out of four is not having read everything.
     *
     * @param list<LessonLogAttachment> $attachments
     */
    public function countStudentsHavingOpenedAll(array $attachments): int
    {
        if ([] === $attachments) {
            return 0;
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.student) AS studentId', 'COUNT(DISTINCT v.attachment) AS opened')
            ->where('v.attachment IN (:attachments)')
            ->groupBy('v.student')
            ->having('COUNT(DISTINCT v.attachment) = :expected')
            ->setParameter('attachments', $attachments)
            ->setParameter('expected', \count($attachments))
            ->getQuery()
            ->getResult();

        return \count($rows);
    }
}
