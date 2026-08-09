<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SuppressedEmailAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SuppressedEmailAddress>
 */
class SuppressedEmailAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuppressedEmailAddress::class);
    }

    public function findOneByAddress(string $address): ?SuppressedEmailAddress
    {
        return $this->findOneBy(['address' => mb_strtolower(trim($address))]);
    }

    public function isSuppressed(string $address): bool
    {
        return null !== $this->findOneByAddress($address);
    }
}
