<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailSuppression;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailSuppression>
 */
class EmailSuppressionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailSuppression::class);
    }

    /** To be queried before any send: writing to a dead address damages the domain's reputation. */
    public function isSuppressed(string $address): bool
    {
        return null !== $this->findOneBy(['address' => mb_strtolower(trim($address))]);
    }
}
