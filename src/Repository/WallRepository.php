<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\Wall;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wall>
 */
class WallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ProgramRepository $programs)
    {
        parent::__construct($registry, Wall::class);
    }

    /**
     * « Mes murs », the last one worked on first.
     *
     * @return list<Wall>
     */
    public function findOwnedBy(User $owner): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('w.updatedAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every wall one of these people owns or is named on, with its owner and its members loaded -
     * the raw material of « Murs étudiants », which App\Service\Wall\StudentWallDirectory then
     * sorts into a class's two sections.
     *
     * @param list<User> $people
     *
     * @return list<Wall>
     */
    public function findInvolving(array $people): array
    {
        if ([] === $people) {
            return [];
        }

        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('w')
            ->select('DISTINCT w.id AS id')
            ->leftJoin('w.members', 'm')
            ->andWhere('w.owner IN (:people) OR m IN (:people)')
            ->setParameter('people', $people)
            ->getQuery()
            ->getArrayResult();

        if ([] === $rows) {
            return [];
        }

        return $this->createQueryBuilder('w')
            ->addSelect('o', 'm')
            ->innerJoin('w.owner', 'o')
            ->leftJoin('w.members', 'm')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', array_column($rows, 'id'))
            ->orderBy('w.updatedAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * « Partagés avec moi »: the walls somebody else owns and opened to this person, by name or
     * through one of their classes. The same two doors App\Service\Wall\WallAccess::roleOf() reads -
     * this is the listing, that is the rule, and every wall is asked again when it is opened.
     *
     * @return list<Wall>
     */
    public function findSharedWith(User $user): array
    {
        $qb = $this->createQueryBuilder('w')
            ->addSelect('o')
            ->innerJoin('w.owner', 'o')
            ->andWhere('w.owner != :user')
            ->setParameter('user', $user)
            ->orderBy('w.updatedAt', 'DESC')
            ->addOrderBy('w.id', 'DESC');

        $programIds = $this->programs->findIdsWithUserAsStudent($user);
        if ([] === $programIds) {
            $qb->andWhere(':user MEMBER OF w.members');
        } else {
            $qb->andWhere(':user MEMBER OF w.members OR EXISTS (SELECT 1 FROM App\Entity\Program p WHERE p MEMBER OF w.programs AND p.id IN (:programIds))')
                ->setParameter('programIds', $programIds);
        }

        return $qb->getQuery()->getResult();
    }
}
