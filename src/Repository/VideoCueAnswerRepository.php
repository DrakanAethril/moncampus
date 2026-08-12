<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\VideoCueAnswer;
use App\Entity\VideoCuePoint;
use App\Entity\VideoResource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoCueAnswer>
 */
class VideoCueAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoCueAnswer::class);
    }

    public function findOneForStudent(VideoCuePoint $cuePoint, User $student): ?VideoCueAnswer
    {
        return $this->findOneBy(['cuePoint' => $cuePoint, 'student' => $student]);
    }

    /**
     * What this student has already answered across a whole video, so the player knows on load
     * which markers to leave alone - a question asked twice on a second viewing would ask for an
     * answer whose first one is the one that counts.
     *
     * @return array<int, bool> cue point id => whether it was answered right
     */
    public function findByCuePointIdForStudent(VideoResource $resource, User $student): array
    {
        /** @var list<array{cueId: int|string, correct: bool|int}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.cuePoint) AS cueId', 'a.correct AS correct')
            ->join('a.cuePoint', 'c')
            ->join('c.file', 'f')
            ->andWhere('f.resource = :resource')
            ->andWhere('a.student = :student')
            ->setParameter('resource', $resource)
            ->setParameter('student', $student)
            ->getQuery()
            ->getScalarResult();

        $answers = [];
        foreach ($rows as $row) {
            $answers[(int) $row['cueId']] = (bool) $row['correct'];
        }

        return $answers;
    }

    /**
     * The teacher's reading of a whole video: how many students answered each marker, and how many
     * got it right (créas 5B, screen 5).
     *
     * Counted in SQL rather than by loading the rows: a class of thirty on a video of ten markers
     * is three hundred rows to build four lines of table.
     *
     * @return array<int, array{answers: int, correct: int}> cue point id => counts
     */
    public function countByCuePointForResource(VideoResource $resource): array
    {
        /** @var list<array{cueId: int|string, total: int|string, correct: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.cuePoint) AS cueId', 'COUNT(a.id) AS total', 'SUM(CASE WHEN a.correct = true THEN 1 ELSE 0 END) AS correct')
            ->join('a.cuePoint', 'c')
            ->join('c.file', 'f')
            ->andWhere('f.resource = :resource')
            ->setParameter('resource', $resource)
            ->groupBy('a.cuePoint')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['cueId']] = [
                'answers' => (int) $row['total'],
                'correct' => (int) $row['correct'],
            ];
        }

        return $counts;
    }

    /** How many distinct students have answered at least one marker of this video. */
    public function countStudentsForResource(VideoResource $resource): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.student)')
            ->join('a.cuePoint', 'c')
            ->join('c.file', 'f')
            ->andWhere('f.resource = :resource')
            ->setParameter('resource', $resource)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
