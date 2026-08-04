<?php

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

    /** À interroger avant tout envoi : écrire à une adresse morte abîme la réputation du domaine. */
    public function isSuppressed(string $address): bool
    {
        return null !== $this->findOneBy(['address' => mb_strtolower(trim($address))]);
    }
}
