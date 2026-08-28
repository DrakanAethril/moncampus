<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameFigure;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameTrack;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameFigure>
 */
class GameFigureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameFigure::class);
    }

    /**
     * The figures still available to one student: the catalogues of their filières, minus the ones
     * already taken in the class, minus the ones they have carried before.
     *
     * Both exclusions matter and for different reasons. The first keeps the patronym unique in the
     * class - the database refuses a duplicate anyway, this is what stops the draw from proposing
     * one. The second is what makes the cursus teach twelve names rather than the same three.
     *
     * **Filières, plural**: a student whose option does not name one draws from every catalogue
     * their formation plays in, which in a SIO class is SLAM and SISR at once.
     *
     * @param list<GameTrack> $tracks
     *
     * @return list<GameFigure>
     */
    public function availableFor(array $tracks, Program $program, User $student): array
    {
        if ([] === $tracks) {
            return [];
        }

        /** @var list<GameFigure> $figures */
        $figures = $this->createQueryBuilder('f')
            ->where('f.track IN (:tracks)')
            ->andWhere('f.active = true')
            ->andWhere('f.id NOT IN (SELECT IDENTITY(taken.figure) FROM App\Entity\GameAlias taken WHERE taken.program = :program AND taken.figure IS NOT NULL)')
            ->andWhere('f.id NOT IN (SELECT IDENTITY(worn.figure) FROM App\Entity\GameAlias worn WHERE worn.student = :student AND worn.figure IS NOT NULL)')
            ->orderBy('f.surname', 'ASC')
            ->setParameter('tracks', $tracks)
            ->setParameter('program', $program)
            ->setParameter('student', $student)
            ->getQuery()
            ->getResult();

        return $figures;
    }

    /**
     * @return list<GameFigure>
     */
    public function forTrack(GameTrack $track): array
    {
        /** @var list<GameFigure> $figures */
        $figures = $this->createQueryBuilder('f')
            ->where('f.track = :track')
            ->orderBy('f.surname', 'ASC')
            ->setParameter('track', $track)
            ->getQuery()
            ->getResult();

        return $figures;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, GameFigure> keyed by id
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<GameFigure> $figures */
        $figures = $this->createQueryBuilder('f')
            ->where('f.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($figures as $figure) {
            $byId[(int) $figure->getId()] = $figure;
        }

        return $byId;
    }

    /** How many entries a filière carries, and how many have been proof-read. */
    public function tally(GameTrack $track): array
    {
        /** @var array{total: int, reviewed: int} $row */
        $row = $this->createQueryBuilder('f')
            ->select('COUNT(f.id) AS total, SUM(CASE WHEN f.reviewed = true THEN 1 ELSE 0 END) AS reviewed')
            ->where('f.track = :track')
            ->setParameter('track', $track)
            ->getQuery()
            ->getSingleResult();

        return ['total' => (int) $row['total'], 'reviewed' => (int) $row['reviewed']];
    }
}
