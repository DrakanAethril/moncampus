<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\WordCloud;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WordCloud>
 */
class WordCloudRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WordCloud::class);
    }

    /**
     * A class's clouds, newest first.
     *
     * Every teacher of the class sees all of them, not only their own: a cloud is run in front of
     * the room, and the colleague taking the next hour needs to be able to reopen the question -
     * which is the same rule WordCloudVoter applies.
     *
     * @return list<WordCloud>
     */
    public function findForProgram(Program $program): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.program = :program')
            ->setParameter('program', $program)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The clouds taking words right now in any of these formations.
     *
     * The `Ouvert` rule of App\Service\WordCloud\WordCloudSchedule, written once more in DQL - and
     * the only place it is. Everything else in the app asks the service; a banner cannot, because
     * it would mean loading every cloud the school has ever run to find the one that is open.
     * Whoever changes the rule changes both, and WordCloudScheduleTest is what pins the meaning.
     *
     * @param list<Program> $programs
     *
     * @return list<WordCloud>
     */
    public function findOpenForPrograms(array $programs, \DateTimeImmutable $now): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.program IN (:programs)')
            ->andWhere('c.closedAt IS NULL')
            ->andWhere('(c.manualOpening = true AND c.openedAt IS NOT NULL AND c.openedAt <= :now) OR (c.manualOpening = false AND c.opensAt IS NOT NULL AND c.opensAt <= :now)')
            ->andWhere('c.closesAt IS NULL OR c.closesAt >= :now')
            ->setParameter('programs', $programs)
            ->setParameter('now', $now)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The teacher's own clouds across their classes - what a reusable creation panel offers when it
     * is opened from somewhere that is not a class screen.
     *
     * @param list<Program> $programs
     *
     * @return list<WordCloud>
     */
    public function findForTeacherAndPrograms(User $teacher, array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.teacher = :teacher')
            ->andWhere('c.program IN (:programs)')
            ->setParameter('teacher', $teacher)
            ->setParameter('programs', $programs)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
