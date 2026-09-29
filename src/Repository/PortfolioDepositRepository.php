<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Portfolio;
use App\Entity\PortfolioDeposit;
use App\Enum\PortfolioExam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortfolioDeposit>
 */
class PortfolioDepositRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortfolioDeposit::class);
    }

    /** @return list<PortfolioDeposit> newest first */
    public function findForPortfolio(Portfolio $portfolio): array
    {
        /** @var list<PortfolioDeposit> $rows */
        $rows = $this->createQueryBuilder('d')
            ->where('d.portfolio = :portfolio')
            ->setParameter('portfolio', $portfolio)
            ->orderBy('d.depositedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function latestFor(Portfolio $portfolio, PortfolioExam $exam, bool $endorsedOnly = false): ?PortfolioDeposit
    {
        $qb = $this->createQueryBuilder('d')
            ->where('d.portfolio = :portfolio')
            ->andWhere('d.exam = :exam')
            ->setParameter('portfolio', $portfolio)
            ->setParameter('exam', $exam)
            ->orderBy('d.depositedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults(1);

        if ($endorsedOnly) {
            $qb->andWhere("d.state = 'endorsed'");
        }

        /** @var PortfolioDeposit|null $row */
        $row = $qb->getQuery()->getOneOrNullResult();

        return $row;
    }

    /**
     * The latest deposit of each exam, for a list of portfolios - the class view reads a class at once.
     *
     * @param list<Portfolio> $portfolios
     *
     * @return array<int, array<string, PortfolioDeposit>> portfolio id => exam value => deposit
     */
    public function latestByPortfolio(array $portfolios): array
    {
        if ([] === $portfolios) {
            return [];
        }

        /** @var list<PortfolioDeposit> $rows */
        $rows = $this->createQueryBuilder('d')
            ->where('d.portfolio IN (:portfolios)')
            ->setParameter('portfolios', $portfolios)
            ->orderBy('d.depositedAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $deposit) {
            $latest[(int) $deposit->getPortfolio()?->getId()][$deposit->getExam()->value] = $deposit;
        }

        return $latest;
    }
}
