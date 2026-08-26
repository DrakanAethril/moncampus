<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizAttempt;
use App\Entity\QuizAttemptEvent;
use App\Enum\QuizAttemptEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizAttemptEvent>
 */
class QuizAttemptEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizAttemptEvent::class);
    }

    /** @return list<QuizAttemptEvent> in the order they happened */
    public function findForAttempt(QuizAttempt $attempt): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.attempt = :attempt')
            ->setParameter('attempt', $attempt)
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.occurredMs', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The absence of that type still waiting for its counterpart, most recent first. Null when the
     * departure beacon never arrived - which the journal handles rather than ignores.
     */
    public function findOpenAbsence(QuizAttempt $attempt, QuizAttemptEventType $type): ?QuizAttemptEvent
    {
        return $this->createQueryBuilder('e')
            ->where('e.attempt = :attempt')
            ->andWhere('e.type = :type')
            ->andWhere('e.durationMs IS NULL')
            ->setParameter('attempt', $attempt)
            ->setParameter('type', $type)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.occurredMs', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The last instant this attempt is known to have been at, for bounding a declared duration.
     * Second precision is plenty here: it bounds a claim, it does not measure an absence.
     */
    public function findLastOccurredAt(QuizAttempt $attempt): ?\DateTimeImmutable
    {
        $event = $this->createQueryBuilder('e')
            ->where('e.attempt = :attempt')
            ->setParameter('attempt', $attempt)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.occurredMs', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $event instanceof QuizAttemptEvent ? $event->getOccurredAt() : null;
    }

    /** How many absences of at least $thresholdMs this attempt has come back from. */
    public function countAbsencesAtLeast(QuizAttempt $attempt, int $thresholdMs): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.attempt = :attempt')
            ->andWhere('e.durationMs >= :threshold')
            ->setParameter('attempt', $attempt)
            ->setParameter('threshold', $thresholdMs)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Rolling retention, called by app:purge-platform-activity - see that command's docblock. */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->where('e.occurredAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    public function countOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.occurredAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
