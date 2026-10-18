<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGameRewardGrant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameRewardGrant>
 */
class SeriousGameRewardGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameRewardGrant::class);
    }

    /** @return list<SeriousGameRewardGrant> what a student holds, the first obtained first */
    public function findForStudent(User $student): array
    {
        return $this->findBy(['student' => $student], ['grantedAt' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneFor(User $student, string $code): ?SeriousGameRewardGrant
    {
        return $this->findOneBy(['student' => $student, 'rewardCode' => $code]);
    }

    /** @return list<string> the pins a student holds */
    public function heldPins(User $student): array
    {
        /** @var list<array{rewardCode: string}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->select('g.rewardCode')
            ->andWhere('g.student = :student')
            ->andWhere('g.rewardCode LIKE :pin')
            ->setParameter('student', $student)
            ->setParameter('pin', 'pin-%')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'rewardCode');
    }
}
