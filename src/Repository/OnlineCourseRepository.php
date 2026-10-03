<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OnlineCourse;
use App\Entity\User;
use App\Enum\OnlineCourseStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OnlineCourse>
 */
class OnlineCourseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnlineCourse::class);
    }

    /**
     * One author's courses, the last one worked on first - drafts included: this is their own list.
     *
     * @return list<OnlineCourse>
     */
    public function findForOwner(User $owner): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.tags', 't')->addSelect('t')
            ->leftJoin('c.materials', 'm')->addSelect('m')
            ->leftJoin('m.revisions', 'r')->addSelect('r')
            ->andWhere('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What an author's public page lists: the courses published as public, by title. A course
     * reserved for learning paths is online and deliberately absent from here.
     *
     * @return list<OnlineCourse>
     */
    public function findPublicForOwner(User $owner): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.tags', 't')->addSelect('t')
            ->leftJoin('c.materials', 'm')->addSelect('m')
            ->leftJoin('m.revisions', 'r')->addSelect('r')
            ->andWhere('c.owner = :owner')
            ->andWhere('c.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', OnlineCourseStatus::PublicCourse)
            ->orderBy('c.title', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPublicForOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.owner = :owner')
            ->andWhere('c.status = :status')
            ->setParameter('owner', $owner)
            ->setParameter('status', OnlineCourseStatus::PublicCourse)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByOwnerAndSlug(User $owner, string $slug): ?OnlineCourse
    {
        return $this->findOneBy(['owner' => $owner, 'slug' => $slug]);
    }

    /**
     * The slugs an author already uses, to number a new one against.
     *
     * @return list<string>
     */
    public function findSlugsForOwner(User $owner, ?OnlineCourse $except = null): array
    {
        $builder = $this->createQueryBuilder('c')
            ->select('c.slug')
            ->andWhere('c.owner = :owner')
            ->setParameter('owner', $owner);

        if (null !== $except && null !== $except->getId()) {
            $builder->andWhere('c.id <> :except')->setParameter('except', $except->getId());
        }

        /** @var list<array{slug: string}> $rows */
        $rows = $builder->getQuery()->getArrayResult();

        return array_column($rows, 'slug');
    }
}
