<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Portfolio;
use App\Entity\Referential;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Portfolio>
 */
class PortfolioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Portfolio::class);
    }

    /**
     * Does any portfolio use this référentiel? From the first one on, its competencies are frozen:
     * claims point at them, and rewriting a column under a validated claim would move a tick.
     */
    public function isReferentialUsed(Referential $referential): bool
    {
        return null !== $this->findOneBy(['referential' => $referential]);
    }

    /**
     * The portfolios of a list of students on one référentiel, with their pieces - the class view
     * and the queue read a whole class at once.
     *
     * @param list<User> $students
     *
     * @return array<int, Portfolio> keyed by student id
     */
    public function findForStudents(Referential $referential, array $students): array
    {
        if ([] === $students) {
            return [];
        }

        /** @var list<Portfolio> $rows */
        $rows = $this->createQueryBuilder('p')
            ->addSelect('a', 'c', 's')
            ->leftJoin('p.achievements', 'a')
            ->leftJoin('a.claims', 'c')
            ->leftJoin('p.showcases', 's')
            ->where('p.referential = :referential')
            ->andWhere('p.student IN (:students)')
            ->setParameter('referential', $referential)
            ->setParameter('students', $students)
            ->getQuery()
            ->getResult();

        $byStudent = [];
        foreach ($rows as $portfolio) {
            $byStudent[(int) $portfolio->getStudent()?->getId()] = $portfolio;
        }

        return $byStudent;
    }
}
