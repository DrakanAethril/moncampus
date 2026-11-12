<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchoolYear;
use App\Entity\SchoolYearImport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolYearImport>
 */
class SchoolYearImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolYearImport::class);
    }

    /**
     * The latest import into each of these years, keyed by the year's id - one query for a whole
     * page of the school-years list.
     *
     * @param list<int> $yearIds
     *
     * @return array<int, SchoolYearImport>
     */
    public function latestByTarget(array $yearIds): array
    {
        if ([] === $yearIds) {
            return [];
        }

        /** @var list<SchoolYearImport> $rows */
        $rows = $this->createQueryBuilder('i')
            ->join('i.source', 's')->addSelect('s')
            ->leftJoin('i.createdBy', 'u')->addSelect('u')
            ->where('IDENTITY(i.target) IN (:years)')
            ->setParameter('years', $yearIds)
            ->orderBy('i.creationDate', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row->getTarget()->getId()] = $row;
        }

        return $latest;
    }

    public function findOneForTarget(SchoolYear $target, int $id): ?SchoolYearImport
    {
        return $this->findOneBy(['id' => $id, 'target' => $target]);
    }
}
