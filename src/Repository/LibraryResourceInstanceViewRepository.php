<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LibraryResourceInstance;
use App\Entity\LibraryResourceInstanceView;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LibraryResourceInstanceView>
 */
class LibraryResourceInstanceViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryResourceInstanceView::class);
    }

    public function findOneFor(LibraryResourceInstance $resource, User $student): ?LibraryResourceInstanceView
    {
        return $this->findOneBy(['resource' => $resource, 'student' => $student]);
    }

    /**
     * Which of these resources one student has already opened.
     *
     * One query for a whole screen rather than one per row: the course space paints an "opened"
     * marker on every resource of a séance, and asking per resource is how a page of twenty
     * handouts turns into twenty-one queries.
     *
     * @param list<LibraryResourceInstance> $resources
     *
     * @return list<int> resource ids
     */
    public function openedResourceIdsFor(array $resources, User $student): array
    {
        if ([] === $resources) {
            return [];
        }

        /** @var list<array{resourceId: int|string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.resource) AS resourceId')
            ->where('v.resource IN (:resources)')
            ->andWhere('v.student = :student')
            ->setParameter('resources', $resources)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['resourceId'], $rows);
    }

    /**
     * The same reading from ids alone, for the access conditions: a resource_viewed leaf names a
     * resource by id, and loading the row to hand it back would be work done for nothing.
     *
     * @param list<int> $resourceIds
     *
     * @return list<int>
     */
    public function findOpenedResourceIdsForStudent(array $resourceIds, User $student): array
    {
        if ([] === $resourceIds) {
            return [];
        }

        /** @var list<array{resourceId: int|string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.resource) AS resourceId')
            ->where('v.resource IN (:resources)')
            ->andWhere('v.student = :student')
            ->setParameter('resources', $resourceIds)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['resourceId'], $rows);
    }

    /**
     * How many distinct students opened each resource - the teacher-side counterpart, kept here so
     * the reporting screens never walk the rows themselves.
     *
     * @param list<LibraryResourceInstance> $resources
     *
     * @return array<int, int> resource id => number of students
     */
    public function countByResource(array $resources): array
    {
        if ([] === $resources) {
            return [];
        }

        /** @var list<array{resourceId: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.resource) AS resourceId', 'COUNT(v.id) AS total')
            ->where('v.resource IN (:resources)')
            ->groupBy('v.resource')
            ->setParameter('resources', $resources)
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['resourceId']] = (int) $row['total'];
        }

        return $counts;
    }
}
