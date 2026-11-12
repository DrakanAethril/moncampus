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
     * Outils › Nuage de mots: the clouds of the classes one runs, and one's own clouds of no class -
     * narrowed to one class, or to the clouds of no class, by the list's filter.
     *
     * @param list<Program> $programs the classes this person may pilot clouds in
     *
     * @return list<WordCloud>
     */
    public function findForPilot(User $user, array $programs, ?Program $only = null, bool $classless = false): array
    {
        $builder = $this->createQueryBuilder('c')
            ->leftJoin('c.program', 'p')->addSelect('p')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC');

        if ($classless) {
            $builder->andWhere('c.program IS NULL')->andWhere('c.teacher = :user')->setParameter('user', $user);
        } elseif (null !== $only) {
            $builder->andWhere('c.program = :program')->setParameter('program', $only);
        } elseif ([] === $programs) {
            $builder->andWhere('c.program IS NULL')->andWhere('c.teacher = :user')->setParameter('user', $user);
        } else {
            $builder->andWhere('(c.program IS NULL AND c.teacher = :user) OR c.program IN (:programs)')
                ->setParameter('user', $user)
                ->setParameter('programs', $programs);
        }

        return $builder->getQuery()->getResult();
    }

    /** A public cloud by the code of its address (/cloud/{code}), whatever the case typed. */
    public function findOneByPublicCode(string $code): ?WordCloud
    {
        return $this->findOneBy(['publicCode' => strtoupper(trim($code))]);
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
