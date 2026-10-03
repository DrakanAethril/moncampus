<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningPath;
use App\Entity\LearningPathEnrollment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningPathEnrollment>
 */
class LearningPathEnrollmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningPathEnrollment::class);
    }

    public function findOneFor(LearningPath $path, User $user): ?LearningPathEnrollment
    {
        return $this->findOneBy(['path' => $path, 'user' => $user]);
    }

    /**
     * « Mes parcours »: what somebody has started, the one last worked on first.
     *
     * @return list<LearningPathEnrollment>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.path', 'p')->addSelect('p')
            ->leftJoin('p.steps', 's')->addSelect('s')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function hasAnyForUser(User $user): bool
    {
        return null !== $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Everybody following a path, with their account - the rows of its author's follow-up.
     *
     * @return list<LearningPathEnrollment>
     */
    public function findForPath(LearningPath $path): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.user', 'u')->addSelect('u')
            ->andWhere('e.path = :path')
            ->setParameter('path', $path)
            ->orderBy('e.startedAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForPath(LearningPath $path): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.path = :path')
            ->setParameter('path', $path)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Removes the follow-ups nobody has touched since the threshold - the retention the follow-up
     * screen announces. The steps opened and the attempts go with their enrollment (ON DELETE
     * CASCADE).
     */
    public function purgeInactiveSince(\DateTimeImmutable $threshold): int
    {
        $deleted = $this->getEntityManager()->createQuery(
            'DELETE FROM '.LearningPathEnrollment::class.' e WHERE e.lastActivityAt < :threshold'
        )->setParameter('threshold', $threshold)->execute();

        return $deleted;
    }
}
