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
     * Résolution du destinataire d'un mail entrant. Volontairement sans filtre sur `active` :
     * une adresse désactivée (ancien nom d'usage) doit continuer à rejoindre son élève, sinon
     * le message tomberait dans la file « à rattacher » alors qu'on sait parfaitement à qui il
     * appartient.
     */
    public function findOneByLocalPart(string $localPart): ?EmailAlias
    {
        return $this->findOneBy(['localPart' => mb_strtolower(trim($localPart))]);
    }

    /** Le garde-fou d'unicité de App\Service\StudentMailAddressGenerator. */
    public function localPartExists(string $localPart): bool
    {
        return null !== $this->findOneByLocalPart($localPart);
    }

    /** L'adresse affichée et expéditrice de l'élève. */
    public function findPrimaryForUser(User $user): ?EmailAlias
    {
        return $this->findOneBy(['user' => $user, 'primary' => true]);
    }

    /** @return list<EmailAlias> */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['primary' => 'DESC', 'createdAt' => 'ASC']);
    }
}
