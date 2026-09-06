<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WordCloudSubmission>
 */
class WordCloudSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WordCloudSubmission::class);
    }

    /**
     * Every word of a cloud, in the order it was written.
     *
     * Chronological rather than sorted: WordCloudAggregator breaks its ties on arrival order, so
     * the board keeps the same layout between two refreshes. Refused and pending words come back
     * too - the callers that build a cloud filter on isCounted(), the ones that build the history
     * do not.
     *
     * @return list<WordCloudSubmission>
     */
    public function findForCloud(WordCloud $cloud): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('u')
            ->join('s.student', 'u')
            ->andWhere('s.wordCloud = :cloud')
            ->setParameter('cloud', $cloud)
            ->orderBy('s.submittedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<WordCloudSubmission> */
    public function findForCloudAndStudent(WordCloud $cloud, User $student): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.wordCloud = :cloud')
            ->andWhere('s.student = :student')
            ->setParameter('cloud', $cloud)
            ->setParameter('student', $student)
            ->orderBy('s.submittedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The moderation queue: what has arrived and not yet been ruled on. */
    public function countPending(WordCloud $cloud): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.wordCloud = :cloud')
            ->andWhere('s.moderationState = :pending')
            ->setParameter('cloud', $cloud)
            ->setParameter('pending', WordCloudModerationState::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many people have written something, per cloud, for a list of clouds at once - what the
     * list screen's « Participation » column needs without loading every word of every cloud.
     *
     * @param list<WordCloud> $clouds
     *
     * @return array<int, int> cloud id => distinct students who submitted
     */
    public function countParticipantsByCloud(array $clouds): array
    {
        if ([] === $clouds) {
            return [];
        }

        /** @var list<array{cloudId: int, participants: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.wordCloud) AS cloudId', 'COUNT(DISTINCT s.student) AS participants')
            ->andWhere('s.wordCloud IN (:clouds)')
            ->setParameter('clouds', $clouds)
            ->groupBy('s.wordCloud')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['cloudId']] = (int) $row['participants'];
        }

        return $counts;
    }
}
