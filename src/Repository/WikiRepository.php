<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wiki;
use App\Enum\WikiType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wiki>
 */
class WikiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wiki::class);
    }

    /**
     * The one personal wiki of a person, if they have asked for one - /wiki/personal redirects to
     * it, or renders the invitation page when this answers null.
     */
    public function findPersonalFor(User $owner): ?Wiki
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.type = :type')
            ->andWhere('w.owner = :owner')
            ->setParameter('type', WikiType::Personal)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * « Wikis partagés »: everything that is not my own personal wiki - assigned to me through one of my
     * classes, created by me, or where I was added as a member.
     *
     * Deliberately not "every wiki I may read": a teacher may read every student wiki in the
     * school, and listing those here would bury the handful that are actually theirs. The
     * supervision list is its own screen.
     *
     * @param list<Program> $programs the classes the person is enrolled in or teaches
     *
     * @return list<Wiki>
     */
    public function findSharedFor(User $user, array $programs, bool $includeArchived = false): array
    {
        $qb = $this->createQueryBuilder('w')
            ->leftJoin('w.members', 'm')
            ->leftJoin('w.programs', 'p')
            ->andWhere('w.type = :shared')
            ->setParameter('shared', WikiType::Shared);

        if ([] === $programs) {
            $qb->andWhere('m = :user OR w.createdBy = :user')
                ->setParameter('user', $user);
        } else {
            $qb->andWhere('m = :user OR w.createdBy = :user OR p IN (:programs)')
                ->setParameter('user', $user)
                ->setParameter('programs', $programs);
        }

        if (!$includeArchived) {
            $qb->andWhere('w.archived = false');
        }

        /** @var list<Wiki> $wikis */
        $wikis = $qb->distinct()
            ->orderBy('w.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $wikis;
    }

    /**
     * Whether « Wikis partagés » would show this person anything at all - what the nav entry is
     * gated on, since an entry that always leads to « aucun wiki » is one people learn to ignore.
     *
     * The same conditions as findSharedFor(), hydrating nothing and stopping at the first row: this
     * runs on every authenticated page, not on the screen it guards. Archived wikis do not count,
     * because the screen does not show them either until somebody asks for them.
     *
     * @param list<Program> $programs the classes the person is enrolled in or teaches
     */
    public function hasSharedFor(User $user, array $programs): bool
    {
        $qb = $this->createQueryBuilder('w')
            ->select('1')
            ->leftJoin('w.members', 'm')
            ->leftJoin('w.programs', 'p')
            ->andWhere('w.type = :shared')
            ->andWhere('w.archived = false')
            ->setParameter('shared', WikiType::Shared)
            ->setMaxResults(1);

        if ([] === $programs) {
            $qb->andWhere('m = :user OR w.createdBy = :user')
                ->setParameter('user', $user);
        } else {
            $qb->andWhere('m = :user OR w.createdBy = :user OR p IN (:programs)')
                ->setParameter('user', $user)
                ->setParameter('programs', $programs);
        }

        return [] !== $qb->getQuery()->getScalarResult();
    }

    /**
     * The personal wikis of a set of students - the content of "Wikis des étudiants", which is
     * grouped by class by its caller.
     *
     * @param list<User> $students
     *
     * @return list<Wiki>
     */
    public function findPersonalOf(array $students): array
    {
        if ([] === $students) {
            return [];
        }

        /** @var list<Wiki> $wikis */
        $wikis = $this->createQueryBuilder('w')
            ->addSelect('o')
            ->join('w.owner', 'o')
            ->andWhere('w.type = :type')
            ->andWhere('w.owner IN (:students)')
            ->setParameter('type', WikiType::Personal)
            ->setParameter('students', $students)
            ->getQuery()
            ->getResult();

        return $wikis;
    }

    /**
     * How many live pages each of the given wikis holds - the "14 pages" of the supervision screen,
     * in one query rather than one per row.
     *
     * @param list<Wiki> $wikis
     *
     * @return array<int, int> wiki id => page count
     */
    public function countPagesOf(array $wikis): array
    {
        if ([] === $wikis) {
            return [];
        }

        /** @var list<array{id: int, pages: int}> $rows */
        $rows = $this->createQueryBuilder('w')
            ->select('w.id AS id, COUNT(n.id) AS pages')
            ->leftJoin('w.nodes', 'n', 'WITH', 'n.deletedAt IS NULL')
            ->andWhere('w IN (:wikis)')
            ->setParameter('wikis', $wikis)
            ->groupBy('w.id')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['id']] = (int) $row['pages'];
        }

        return $counts;
    }
}
