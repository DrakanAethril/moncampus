<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JobboardBatch;
use App\Entity\JobboardBatchRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JobboardBatchRequest>
 */
class JobboardBatchRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JobboardBatchRequest::class);
    }

    public function findOneByKey(JobboardBatch $batch, string $idempotencyKey): ?JobboardBatchRequest
    {
        return $this->findOneBy(['batch' => $batch, 'idempotencyKey' => $idempotencyKey]);
    }
}
