<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CrmJournalEntry;
use App\Entity\Program;
use App\Enum\CrmJournalKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmJournalEntry>
 */
class CrmJournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmJournalEntry::class);
    }

    /**
     * The journal lines of a year's formations, the latest first - « Historique ».
     *
     * @param list<Program> $programs
     * @param list<string>  $words    normalised words the student's name must contain
     *
     * @return array{rows: list<CrmJournalEntry>, total: int}
     */
    public function history(array $programs, ?CrmJournalKind $kind, ?Program $program, ?string $author, bool $withEntries, array $words, int $page, int $perPage): array
    {
        if ([] === $programs) {
            return ['rows' => [], 'total' => 0];
        }

        $query = $this->filtered($programs, $kind, $program, $author, $withEntries, $words);
        $total = (int) (clone $query)->select('COUNT(j.id)')->getQuery()->getSingleScalarResult();

        /** @var list<CrmJournalEntry> $rows */
        $rows = $query
            ->addSelect('d', 'c', 'p')
            ->orderBy('j.occurredOn', 'DESC')
            ->addOrderBy('j.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Who wrote in these formations' journals, for the « Par » filter.
     *
     * @param list<Program> $programs
     *
     * @return list<string>
     */
    public function authors(array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        /** @var list<array{authorName: string}> $rows */
        $rows = $this->createQueryBuilder('j')
            ->select('DISTINCT j.authorName')
            ->join('j.dossier', 'd')
            ->andWhere('d.program IN (:programs)')
            ->setParameter('programs', $programs)
            ->orderBy('j.authorName', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['authorName'], $rows);
    }

    /**
     * @param list<Program> $programs
     * @param list<string>  $words
     */
    private function filtered(array $programs, ?CrmJournalKind $kind, ?Program $program, ?string $author, bool $withEntries, array $words): QueryBuilder
    {
        $query = $this->createQueryBuilder('j')
            ->join('j.dossier', 'd')
            ->join('d.candidate', 'c')
            ->join('d.program', 'p')
            ->andWhere('d.program IN (:programs)')
            ->setParameter('programs', $programs);

        if (null !== $kind) {
            $query->andWhere('j.kind = :kind')->setParameter('kind', $kind);
        }
        if (!$withEntries) {
            $query->andWhere('j.kind != :entry')->setParameter('entry', CrmJournalKind::Entry);
        }
        if (null !== $program) {
            $query->andWhere('d.program = :program')->setParameter('program', $program);
        }
        if (null !== $author && '' !== $author) {
            $query->andWhere('j.authorName = :author')->setParameter('author', $author);
        }
        foreach ($words as $i => $word) {
            $query->andWhere(\sprintf("CONCAT(c.lastName, ' ', c.firstName) LIKE :w%d", $i))
                ->setParameter('w'.$i, '%'.addcslashes($word, '%_').'%');
        }

        return $query;
    }
}
