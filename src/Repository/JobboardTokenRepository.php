<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardToken>
 */
class JobboardTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardToken::class);
    }

    /**
     * The lookup half of the selector/verifier pair: indexed, unique, and telling nothing on its
     * own - the verifier is what is then compared with hash_equals().
     */
    public function findOneBySelector(string $selector): ?JobboardToken
    {
        return $this->findOneBy(['selector' => $selector]);
    }

    /** @return list<JobboardToken> */
    public function findForScreen(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->leftJoin('t.section', 's')
            ->orderBy('t.revokedAt', 'ASC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
