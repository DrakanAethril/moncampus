<?php

namespace App\Repository;

use App\Entity\SignupList;
use App\Entity\SignupListRegistration;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SignupListRegistration>
 */
class SignupListRegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SignupListRegistration::class);
    }

    public function findOneForSignupListAndUser(SignupList $signupList, User $user): ?SignupListRegistration
    {
        return $this->findOneBy(['signupList' => $signupList, 'user' => $user]);
    }

    /** @return list<SignupListRegistration> */
    public function findAllForSignupList(SignupList $signupList): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('u')
            ->innerJoin('r.user', 'u')
            ->where('r.signupList = :signupList')
            ->setParameter('signupList', $signupList)
            ->orderBy('r.registeredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForSignupList(SignupList $signupList): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.signupList = :signupList')
            ->setParameter('signupList', $signupList)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
