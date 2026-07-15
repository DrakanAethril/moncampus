<?php

namespace App\Repository;

use App\Entity\LdapManagePassword;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LdapManagePassword>
 */
class LdapManagePasswordRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly string $ldapPasswordAesKey,
    ) {
        parent::__construct($registry, LdapManagePassword::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<LdapManagePassword> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->join('p.user', 'u')
            ->andWhere('p.login LIKE :search OR CONCAT(u.firstname, \' \', u.lastname) LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // Decrypts the password column via MySQL's own AES_DECRYPT (not reimplemented in PHP - see
    // AES_KEY's .env comment for why the key derivation has to stay MySQL-side) using the raw
    // DBAL connection, only for a row that finished successfully. Returns null once there is
    // nothing (yet, or ever) to decrypt.
    public function decryptPassword(LdapManagePassword $ldapManagePassword): ?string
    {
        if (2 !== $ldapManagePassword->getState()) {
            return null;
        }

        return $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT CAST(AES_DECRYPT(password, :key) AS CHAR) FROM ldap_manage_password WHERE id = :id',
            ['key' => $this->ldapPasswordAesKey, 'id' => $ldapManagePassword->getId()],
        ) ?: null;
    }

    // Symmetric counterpart to decryptPassword() - used by the profile page's self-service
    // change-password flow (App\Controller\ProfileController::changePassword()) to pre-fill a
    // specific requested password, encrypted the same MySQL-side way the consumer script writes
    // the result back (see App\Entity\LdapManagePassword's own docblock on the $password
    // column). Raw DBAL, not a mapped Doctrine property, for the same reason as decryptPassword().
    public function setRequestedPassword(LdapManagePassword $ldapManagePassword, string $password): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE ldap_manage_password SET password = AES_ENCRYPT(:password, :key) WHERE id = :id',
            ['password' => $password, 'key' => $this->ldapPasswordAesKey, 'id' => $ldapManagePassword->getId()],
        );
    }

    // Backs the profile page's "last request" status badge - the self-service flow only ever
    // needs the single latest row per user, unlike Directory > Mots de passe's full paginated
    // history across every user.
    public function findMostRecentForUser(User $user): ?LdapManagePassword
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
