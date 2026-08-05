<?php

namespace App\Repository;

use App\Entity\EmailAlias;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailAlias>
 */
class EmailAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailAlias::class);
    }

    /**
     * Resolves the recipient of an inbound mail. Deliberately without a filter on `active`: a
     * disabled address (a former name in use) must keep reaching its student, otherwise the message
     * would fall into the "to be linked" queue while we know perfectly well whose it is.
     */
    public function findOneByLocalPart(string $localPart): ?EmailAlias
    {
        return $this->findOneBy(['localPart' => mb_strtolower(trim($localPart))]);
    }

    /** The uniqueness guard of App\Service\StudentMailAddressGenerator. */
    public function localPartExists(string $localPart): bool
    {
        return null !== $this->findOneByLocalPart($localPart);
    }

    /**
     * @return list<EmailAlias>
     *
     * The primary address is not looked up here: it is read straight off
     * App\Entity\User::getPrimaryAlias(). Hence the plain oldest-first ordering.
     */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC']);
    }
}
