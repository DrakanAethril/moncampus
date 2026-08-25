<?php

declare(strict_types=1);

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

    // The only thing this application ever does with the password column: write it. The profile
    // page's self-service change-password flow (App\Controller\ProfileController::changePassword())
    // pre-fills the password the user chose, so the consumer script applies that one instead of
    // generating a random one. Encrypted MySQL-side because the key derivation has to stay there
    // (see AES_KEY's .env comment) - hence raw DBAL rather than a mapped Doctrine property.
    //
    // Nothing reads it back. The counterpart decryptPassword() existed until 2026-08-24, to show a
    // generated password on Annuaire > Mots de passe; it was removed with the screen's "Voir"
    // button, and the consumer script now clears the column as its last act. A password lives in
    // this table for as long as the queue takes to drain, and not one moment longer - so do not
    // reintroduce a read path here, whatever screen seems to want one.
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
