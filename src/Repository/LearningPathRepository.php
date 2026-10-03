<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningPath;
use App\Entity\OnlineCourse;
use App\Entity\User;
use App\Enum\LearningPathStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningPath>
 */
class LearningPathRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningPath::class);
    }

    /**
     * One author's paths, drafts included, the last one worked on first.
     *
     * @return list<LearningPath>
     */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.steps', 's')->addSelect('s')
            ->andWhere('p.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.updatedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What the « Parcours » tab of a teacher's page lists, for a signed-in visitor.
     *
     * @return list<LearningPath>
     */
    public function findPublishedForOwner(User $owner): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.steps', 's')->addSelect('s')
            ->andWhere('p.owner = :owner')
            ->andWhere('p.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', LearningPathStatus::Published)
            ->orderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The paths that line this course up - what its card names before it is deleted, and what a
     * tool answers when asked where a course is used.
     *
     * @return list<LearningPath>
     */
    public function findUsingCourse(OnlineCourse $course): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.steps', 's')
            ->andWhere('s.course = :course')
            ->setParameter('course', $course)
            ->orderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
