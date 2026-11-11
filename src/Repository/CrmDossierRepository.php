<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CrmCandidate;
use App\Entity\CrmDossier;
use App\Entity\Program;
use App\Entity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmDossier>
 */
class CrmDossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmDossier::class);
    }

    /**
     * The dossier of the formation whose candidate already holds this mail, the given candidate
     * apart: a mail serves one dossier per formation (and a formation is one year's).
     */
    public function findHoldingMail(Program $program, string $mail, ?CrmCandidate $except = null): ?CrmDossier
    {
        $query = $this->createQueryBuilder('d')
            ->join('d.candidate', 'c')
            ->andWhere('d.program = :program')
            ->andWhere('c.email = :mail')
            ->setParameter('program', $program)
            ->setParameter('mail', mb_strtolower(trim($mail)))
            ->setMaxResults(1);

        if (null !== $except && null !== $except->getId()) {
            $query->andWhere('c != :except')->setParameter('except', $except);
        }

        $found = $query->getQuery()->getOneOrNullResult();

        return $found instanceof CrmDossier ? $found : null;
    }

    /**
     * Every dossier of these formations with what the board and the overview read of it, in a
     * fixed number of queries.
     *
     * @param list<Program> $programs
     *
     * @return list<CrmDossier>
     */
    public function forPrograms(array $programs): array
    {
        if ([] === $programs) {
            return [];
        }

        /** @var list<CrmDossier> $dossiers */
        $dossiers = $this->createQueryBuilder('d')
            ->addSelect('c', 'pieces', 'piece', 'o', 'opt')
            ->join('d.candidate', 'c')
            ->leftJoin('d.pieces', 'pieces')
            ->leftJoin('pieces.piece', 'piece')
            ->leftJoin('d.origins', 'o')
            ->leftJoin('d.option', 'opt')
            ->andWhere('d.program IN (:programs)')
            ->setParameter('programs', $programs)
            ->orderBy('c.lastName', 'ASC')
            ->addOrderBy('c.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        return $dossiers;
    }

    /**
     * The dossiers of opened years whose candidate's names start with every word typed.
     *
     * @param list<SchoolYear> $years
     * @param list<string>     $words normalised words (lower case, no accent)
     *
     * @return list<CrmDossier>
     */
    public function search(array $years, array $words, int $limit = 60): array
    {
        if ([] === $years || [] === $words) {
            return [];
        }

        $query = $this->createQueryBuilder('d')
            ->addSelect('c', 'p')
            ->join('d.candidate', 'c')
            ->join('d.program', 'p')
            ->andWhere('p.schoolYear IN (:years)')
            ->setParameter('years', $years)
            ->setMaxResults($limit);

        // The SQL only narrows on each word anywhere in the name (the collation ignores accents);
        // App\Service\Crm\CrmSearch then keeps the names where every word starts a word.
        foreach ($words as $i => $word) {
            $query->andWhere(\sprintf("CONCAT(c.lastName, ' ', c.firstName) LIKE :w%d", $i))
                ->setParameter('w'.$i, '%'.addcslashes($word, '%_').'%');
        }

        /** @var list<CrmDossier> $dossiers */
        $dossiers = $query->getQuery()->getResult();

        return $dossiers;
    }

    /**
     * How many dossiers each formation holds, and per option.
     *
     * @param list<Program> $programs
     *
     * @return array{programs: array<int, int>, options: array<int, array<int, int>>}
     */
    public function counts(array $programs): array
    {
        if ([] === $programs) {
            return ['programs' => [], 'options' => []];
        }

        /** @var list<array{program: int, option: int|null, n: int}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.program) AS program', 'IDENTITY(d.option) AS option', 'COUNT(d.id) AS n')
            ->andWhere('d.program IN (:programs)')
            ->setParameter('programs', $programs)
            ->groupBy('program', 'option')
            ->getQuery()
            ->getArrayResult();

        $counts = ['programs' => [], 'options' => []];
        foreach ($rows as $row) {
            $program = (int) $row['program'];
            $counts['programs'][$program] = ($counts['programs'][$program] ?? 0) + (int) $row['n'];
            if (null !== $row['option']) {
                $counts['options'][$program][(int) $row['option']] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /** @param list<SchoolYear> $years
     *
     * @return array<int, int> dossiers per school year id
     */
    public function countsByYear(array $years): array
    {
        if ([] === $years) {
            return [];
        }

        /** @var list<array{year: int, n: int}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(p.schoolYear) AS year', 'COUNT(d.id) AS n')
            ->join('d.program', 'p')
            ->andWhere('p.schoolYear IN (:years)')
            ->setParameter('years', $years)
            ->groupBy('year')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['year']] = (int) $row['n'];
        }

        return $counts;
    }
}
