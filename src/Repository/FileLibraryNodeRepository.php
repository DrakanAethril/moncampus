<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FileLibraryNode>
 */
class FileLibraryNodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FileLibraryNode::class);
    }

    /**
     * How many bytes this library holds - **measured, never stored**
     * (design/validated/file-library.md, "Usage is measured, never stored").
     *
     * A stored counter would be a second truth to keep correct through every delete, replace, move
     * and failed upload, and it is wrong the first time one of those paths forgets it. A library
     * holds hundreds of rows, not millions, and the index on (owner_id, type) is what makes the
     * sum cheap.
     *
     * `deletedAt IS NULL` is the corbeille's own rule: a deleted file stops counting the moment it
     * is deleted, thirty days before its bytes go. Freeing space has to be visible when it is asked
     * for - a teacher who deletes 300 Mo and sees the bar hold still will delete something else.
     */
    public function usedBytes(User $owner): int
    {
        $sum = $this->createQueryBuilder('n')
            ->select('COALESCE(SUM(n.sizeBytes), 0)')
            ->where('n.owner = :owner')
            ->andWhere('n.type = :file')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('file', FileLibraryNodeType::File)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $sum;
    }

    /**
     * Every live folder of a library, flat, for the rail. Files are the table's business - a rail
     * listing both would be the same list twice.
     *
     * @return list<FileLibraryNode>
     */
    public function findFolders(User $owner): array
    {
        /** @var list<FileLibraryNode> $folders */
        $folders = $this->createQueryBuilder('n')
            ->where('n.owner = :owner')
            ->andWhere('n.type = :folder')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('folder', FileLibraryNodeType::Folder)
            ->orderBy('n.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $folders;
    }

    /**
     * What one folder holds, folders first then files, in the reader's chosen order.
     *
     * @param 'name'|'size'|'date' $sort
     *
     * @return list<FileLibraryNode>
     */
    public function findChildren(User $owner, ?FileLibraryNode $parent, string $sort = 'name'): array
    {
        $builder = $this->createQueryBuilder('n')
            ->where('n.owner = :owner')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('owner', $owner);

        if (null === $parent) {
            $builder->andWhere('n.parent IS NULL');
        } else {
            $builder->andWhere('n.parent = :parent')->setParameter('parent', $parent);
        }

        // Folders before files whatever the sort: a folder is a place and a file is a thing, and a
        // listing that interleaves them makes the reader check the icon on every line.
        //
        // Ranked rather than ordered by the column itself: `type` holds the enum's *value*, and
        // 'file' sorts before 'folder' - so the obvious `ORDER BY n.type ASC` listed the files first
        // and read as intentional. The rank says what is meant and survives a renamed case. It is
        // selected as a HIDDEN alias because DQL's ORDER BY grammar takes no inline CASE, the same
        // constraint QuizTemplateRepository::findForTeacher() meets with its COALESCE.
        $builder
            ->addSelect('CASE WHEN n.type = :folderType THEN 0 ELSE 1 END AS HIDDEN typeRank')
            ->setParameter('folderType', FileLibraryNodeType::Folder)
            ->addOrderBy('typeRank', 'ASC');

        match ($sort) {
            'size' => $builder->addOrderBy('n.sizeBytes', 'DESC'),
            'date' => $builder->addOrderBy('n.lastUpdatedDate', 'DESC')->addOrderBy('n.creationDate', 'DESC'),
            default => $builder->addOrderBy('n.name', 'ASC'),
        };

        /** @var list<FileLibraryNode> $children */
        $children = $builder->getQuery()->getResult();

        return $children;
    }

    /**
     * A name search over the whole library - names only, never contents
     * (design/validated/file-library.md, "Non-goals").
     *
     * @return list<FileLibraryNode>
     */
    public function search(User $owner, string $terms, int $limit = 50): array
    {
        /** @var list<FileLibraryNode> $found */
        $found = $this->createQueryBuilder('n')
            ->where('n.owner = :owner')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.name LIKE :terms')
            ->setParameter('owner', $owner)
            ->setParameter('terms', '%'.addcslashes($terms, '%_').'%')
            // Folders first here too, and ranked for the same reason as findChildren() above.
            ->addSelect('CASE WHEN n.type = :folderType THEN 0 ELSE 1 END AS HIDDEN typeRank')
            ->setParameter('folderType', FileLibraryNodeType::Folder)
            ->addOrderBy('typeRank', 'ASC')
            ->addOrderBy('n.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $found;
    }

    /**
     * The corbeille: what this owner deleted and may still get back, most recent first.
     *
     * A folder deleted with its contents shows **only the folder**: its children carry the same
     * deletedAt, and listing forty rows for one gesture would bury the one line the teacher is
     * looking for.
     *
     * @return list<FileLibraryNode>
     */
    public function findDeleted(User $owner): array
    {
        /** @var list<FileLibraryNode> $deleted */
        $deleted = $this->createQueryBuilder('n')
            ->leftJoin('n.parent', 'p')
            ->where('n.owner = :owner')
            ->andWhere('n.deletedAt IS NOT NULL')
            ->andWhere('p.id IS NULL OR p.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('n.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $deleted;
    }

    /**
     * The names already taken among a node's siblings - what
     * App\Service\FileLibraryTree::uniqueName() is asked about.
     *
     * @return list<string>
     */
    public function siblingNames(User $owner, ?FileLibraryNode $parent, ?int $exceptId = null): array
    {
        $builder = $this->createQueryBuilder('n')
            ->select('n.name')
            ->where('n.owner = :owner')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('owner', $owner);

        if (null === $parent) {
            $builder->andWhere('n.parent IS NULL');
        } else {
            $builder->andWhere('n.parent = :parent')->setParameter('parent', $parent);
        }

        if (null !== $exceptId) {
            $builder->andWhere('n.id <> :except')->setParameter('except', $exceptId);
        }

        /** @var list<array{name: string}> $rows */
        $rows = $builder->getQuery()->getArrayResult();

        return array_map(static fn (array $row): string => $row['name'], $rows);
    }

    /** The live sibling carrying exactly this name, if there is one - the "Remplacer ?" question. */
    public function findSiblingNamed(User $owner, ?FileLibraryNode $parent, string $name): ?FileLibraryNode
    {
        $builder = $this->createQueryBuilder('n')
            ->where('n.owner = :owner')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.name = :name')
            ->setParameter('owner', $owner)
            ->setParameter('name', $name)
            ->setMaxResults(1);

        if (null === $parent) {
            $builder->andWhere('n.parent IS NULL');
        } else {
            $builder->andWhere('n.parent = :parent')->setParameter('parent', $parent);
        }

        /** @var ?FileLibraryNode $sibling */
        $sibling = $builder->getQuery()->getOneOrNullResult();

        return $sibling;
    }

    /**
     * Every node of a subtree, the root included - what a move rewrites and what a delete marks.
     *
     * @return list<FileLibraryNode>
     */
    public function findSubtree(FileLibraryNode $node): array
    {
        /** @var list<FileLibraryNode> $subtree */
        $subtree = $this->createQueryBuilder('n')
            ->where('n.owner = :owner')
            ->andWhere('n.id = :id OR n.path LIKE :pattern')
            ->setParameter('owner', $node->getOwner())
            ->setParameter('id', $node->getId())
            ->setParameter('pattern', $node->getPath().$node->getId().'/%')
            ->getQuery()
            ->getResult();

        return $subtree;
    }

    /** How many live files a folder holds, at any depth - the folder row's "taille" column. */
    public function countFilesUnder(FileLibraryNode $folder): int
    {
        $count = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.owner = :owner')
            ->andWhere('n.type = :file')
            ->andWhere('n.deletedAt IS NULL')
            ->andWhere('n.path LIKE :pattern')
            ->setParameter('owner', $folder->getOwner())
            ->setParameter('file', FileLibraryNodeType::File)
            ->setParameter('pattern', $folder->getPath().$folder->getId().'/%')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
