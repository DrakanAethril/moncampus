<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuizFolder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuizFolder>
 */
class QuizFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuizFolder::class);
    }

    /**
     * Every folder of one library, flat - what the rail is assembled from.
     *
     * @return list<QuizFolder>
     */
    public function findAllFor(User $owner): array
    {
        /** @var list<QuizFolder> $folders */
        $folders = $this->createQueryBuilder('f')
            ->where('f.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $folders;
    }

    /**
     * The sub-folders of one folder, by name - the rows the listing draws before the quizzes.
     *
     * @return list<QuizFolder>
     */
    public function findChildren(User $owner, ?QuizFolder $parent): array
    {
        $builder = $this->createQueryBuilder('f')
            ->where('f.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('f.name', 'ASC');

        if (null === $parent) {
            $builder->andWhere('f.parent IS NULL');
        } else {
            $builder->andWhere('f.parent = :parent')->setParameter('parent', $parent);
        }

        /** @var list<QuizFolder> $children */
        $children = $builder->getQuery()->getResult();

        return $children;
    }

    /**
     * The names already taken among a folder's siblings - what App\Service\QuizFolderTree needs to
     * answer « Nouveau dossier », « Nouveau dossier (2) ».
     *
     * @return list<string>
     */
    public function siblingNames(User $owner, ?QuizFolder $parent, ?int $exceptId = null): array
    {
        $builder = $this->createQueryBuilder('f')
            ->select('f.name')
            ->where('f.owner = :owner')
            ->setParameter('owner', $owner);

        if (null === $parent) {
            $builder->andWhere('f.parent IS NULL');
        } else {
            $builder->andWhere('f.parent = :parent')->setParameter('parent', $parent);
        }

        if (null !== $exceptId) {
            $builder->andWhere('f.id <> :except')->setParameter('except', $exceptId);
        }

        /** @var list<array{name: string}> $rows */
        $rows = $builder->getQuery()->getArrayResult();

        return array_map(static fn (array $row): string => $row['name'], $rows);
    }

    /**
     * A folder and everything under it, read off the materialized path - one query whatever the
     * depth. The subtree a move rewrites, and the one a deletion promotes.
     *
     * @return list<QuizFolder>
     */
    public function findSubtree(QuizFolder $folder): array
    {
        /** @var list<QuizFolder> $subtree */
        $subtree = $this->createQueryBuilder('f')
            ->where('f.owner = :owner')
            ->andWhere('f.id = :id OR f.path LIKE :pattern')
            ->setParameter('owner', $folder->getOwner())
            ->setParameter('id', $folder->getId())
            ->setParameter('pattern', $folder->childPath().'%')
            ->getQuery()
            ->getResult();

        return $subtree;
    }
}
