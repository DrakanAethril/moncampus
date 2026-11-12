<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport;

use App\Entity\Program;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads what hangs off the formations of an import with a fixed number of queries per block, not
 * per formation (design/validated/school-year-import.md §6): every row of a class for every source
 * and matched target formation at once, grouped by formation, the first time a copier asks.
 *
 * Built for one run and dropped with it - nothing is memoised across requests.
 */
final class SchoolYearImportData
{
    /** @var list<int> */
    private array $programIds;

    /** @var array<string, array<int, list<object>>> class + joins => program id => rows */
    private array $rows = [];

    /** @param list<Program> $programs the source formations and the target formations already in the database */
    public function __construct(private readonly EntityManagerInterface $entityManager, array $programs)
    {
        $ids = [];
        foreach ($programs as $program) {
            if (null !== $program->getId()) {
                $ids[] = $program->getId();
            }
        }
        $this->programIds = array_values(array_unique($ids));
        $this->warmPrograms();
    }

    /**
     * Every row of `$class` hanging off `$program` (a `program` association), in id order. A
     * formation not in the database yet has none.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param list<string>    $joins collections of the row to load with it (`teachers`, `skills`…)
     *
     * @return list<T>
     */
    public function rowsOf(string $class, ?Program $program, array $joins = []): array
    {
        $id = $program?->getId();
        if (null === $id) {
            return [];
        }

        $cacheKey = $class.'|'.implode(',', $joins);
        if (!isset($this->rows[$cacheKey])) {
            $this->rows[$cacheKey] = $this->load($class, $joins);
        }

        /** @var list<T> $rows */
        $rows = $this->rows[$cacheKey][$id] ?? [];

        return $rows;
    }

    /**
     * @param class-string $class
     * @param list<string> $joins
     *
     * @return array<int, list<object>>
     */
    private function load(string $class, array $joins): array
    {
        if ([] === $this->programIds) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('r', 'IDENTITY(r.program) AS pid')
            ->from($class, 'r')
            ->where('r.program IN (:programs)')
            ->setParameter('programs', $this->programIds)
            ->orderBy('r.id', 'ASC');
        foreach ($joins as $i => $join) {
            $qb->leftJoin('r.'.$join, 'j'.$i)->addSelect('j'.$i);
        }

        /** @var list<array{0: object, pid: int|string}> $result */
        $result = $qb->getQuery()->getResult();

        $grouped = [];
        $seen = [];
        foreach ($result as $row) {
            $entity = $row[0];
            if (isset($seen[spl_object_id($entity)])) {
                continue;
            }
            $seen[spl_object_id($entity)] = true;
            $grouped[(int) $row['pid']][] = $entity;
        }

        return $grouped;
    }

    /** One query per collection of Program the copiers read, for all the formations at once. */
    private function warmPrograms(): void
    {
        if ([] === $this->programIds) {
            return;
        }

        foreach (['teachers', 'referentTeachers', 'options', 'modalities', 'programPeriodGroups'] as $collection) {
            $this->entityManager->createQueryBuilder()
                ->select('p', 'c')
                ->from(Program::class, 'p')
                ->leftJoin('p.'.$collection, 'c')
                ->where('p.id IN (:programs)')
                ->setParameter('programs', $this->programIds)
                ->getQuery()
                ->getResult();
        }
    }
}
