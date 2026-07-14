<?php

namespace App\Repository;

use App\Entity\Laptop;
use App\Entity\LaptopLoan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LaptopLoan>
 */
class LaptopLoanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LaptopLoan::class);
    }

    public function findActiveLoanForLaptop(Laptop $laptop): ?LaptopLoan
    {
        return $this->createQueryBuilder('loan')
            ->andWhere('loan.laptop = :laptop')
            ->andWhere('loan.returnedAt IS NULL')
            ->setParameter('laptop', $laptop)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<int> $laptopIds
     *
     * @return array<int, LaptopLoan> active loan indexed by laptop id
     */
    public function findActiveLoansByLaptopIds(array $laptopIds): array
    {
        if ([] === $laptopIds) {
            return [];
        }

        $loans = $this->createQueryBuilder('loan')
            ->leftJoin('loan.borrower', 'b')->addSelect('b')
            ->andWhere('loan.laptop IN (:laptopIds)')
            ->andWhere('loan.returnedAt IS NULL')
            ->setParameter('laptopIds', $laptopIds)
            ->getQuery()
            ->getResult();

        $byLaptopId = [];
        foreach ($loans as $loan) {
            $byLaptopId[$loan->getLaptop()->getId()] = $loan;
        }

        return $byLaptopId;
    }

    public function countForLaptop(Laptop $laptop): int
    {
        return (int) $this->createQueryBuilder('loan')
            ->select('COUNT(loan.id)')
            ->andWhere('loan.laptop = :laptop')
            ->setParameter('laptop', $laptop)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<LaptopLoan> */
    public function findPageForLaptop(Laptop $laptop, int $offset, int $limit): array
    {
        return $this->createQueryBuilder('loan')
            ->leftJoin('loan.borrower', 'b')->addSelect('b')
            ->leftJoin('loan.lentBy', 'lb')->addSelect('lb')
            ->leftJoin('loan.returnedBy', 'rb')->addSelect('rb')
            ->andWhere('loan.laptop = :laptop')
            ->setParameter('laptop', $laptop)
            ->orderBy('loan.lentAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(?string $search = null, bool $onlyActive = false): int
    {
        $qb = $this->createQueryBuilder('loan')
            ->select('COUNT(loan.id)')
            ->leftJoin('loan.laptop', 'l')
            ->leftJoin('loan.borrower', 'b');
        $this->applySearch($qb, $search);
        $this->applyOnlyActive($qb, $onlyActive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<LaptopLoan> */
    public function findPage(int $offset, int $limit, ?string $search = null, bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('loan')
            ->leftJoin('loan.laptop', 'l')->addSelect('l')
            ->leftJoin('loan.borrower', 'b')->addSelect('b')
            ->leftJoin('loan.lentBy', 'lb')->addSelect('lb')
            ->leftJoin('loan.returnedBy', 'rb')->addSelect('rb')
            ->orderBy('loan.lentAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyOnlyActive($qb, $onlyActive);

        return $qb->getQuery()->getResult();
    }

    // Same query as findPage() without the offset/limit - backs the "Exporter" CSV action
    // (App\Controller\LaptopController::exportLoans()), which needs every matching row at once.
    /** @return list<LaptopLoan> */
    public function findAllMatching(?string $search = null, bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('loan')
            ->leftJoin('loan.laptop', 'l')->addSelect('l')
            ->leftJoin('loan.borrower', 'b')->addSelect('b')
            ->leftJoin('loan.lentBy', 'lb')->addSelect('lb')
            ->leftJoin('loan.returnedBy', 'rb')->addSelect('rb')
            ->orderBy('loan.lentAt', 'DESC');
        $this->applySearch($qb, $search);
        $this->applyOnlyActive($qb, $onlyActive);

        return $qb->getQuery()->getResult();
    }

    // Relies on 'l' (loan.laptop) and 'b' (loan.borrower) already being joined by the caller.
    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('l.assetTag LIKE :search OR CONCAT(b.firstname, \' \', b.lastname) LIKE :search OR b.username LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyOnlyActive(QueryBuilder $qb, bool $onlyActive): void
    {
        if ($onlyActive) {
            $qb->andWhere('loan.returnedAt IS NULL');
        }
    }
}
