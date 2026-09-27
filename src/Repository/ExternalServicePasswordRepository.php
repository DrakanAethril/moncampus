<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalServicePassword;
use App\Entity\User;
use App\Enum\ExternalService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalServicePassword>
 */
class ExternalServicePasswordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalServicePassword::class);
    }

    public function findFor(User $user, ExternalService $service): ?ExternalServicePassword
    {
        return $this->findOneBy(['user' => $user, 'service' => $service]);
    }
}
