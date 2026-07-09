<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    // Powers the Gestion > Users list (App\Controller\UserManagementController) - active users
    // only (editing contact info/group assignment for someone who's left isn't a case worth
    // building UI for right now).
    public function countAllForListing(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('u')->select('COUNT(u.id)')->where('u.inactiveDate IS NULL');
        $this->applyListingSearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<User> */
    public function findPageForListing(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.inactiveDate IS NULL')
            ->orderBy('u.firstname', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applyListingSearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    private function applyListingSearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('u.username LIKE :search OR CONCAT(u.firstname, \' \', u.lastname) LIKE :search OR u.email LIKE :search OR u.contactEmail LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // Powers the "add a student/teacher" candidate lists: active users not already linked to
    // the program, matching ALL of the given roles (e.g. the class's own LDAP-group role plus
    // ROLE_STUDENT/ROLE_TEACHER). Roles are stored as a JSON column with no portable DQL way to
    // query "array contains" across DB engines, so the DB only filters what it can
    // (inactiveDate, exclusion, search) and role matching happens in PHP - fine at this scale
    // (a school's user roster, not millions of rows).
    /**
     * @param list<string> $requiredRoles
     * @param list<int>    $excludedIds
     *
     * @return list<User>
     */
    public function findActiveMatchingRoles(array $requiredRoles, array $excludedIds = [], ?string $search = null): array
    {
        return array_values(array_filter(
            $this->findActiveCandidates($excludedIds, $search),
            static fn (User $user): bool => [] === array_diff($requiredRoles, $user->getRoles()),
        ));
    }

    // Same idea as findActiveMatchingRoles(), but for callers that only need ANY one of several
    // roles to match (e.g. "any handler role") rather than all of them - used for the ticket
    // assignee picker, where admin/staff/staff-lead/support-tech are all valid assignees.
    /**
     * @param list<string> $anyOfRoles
     * @param list<int>    $excludedIds
     *
     * @return list<User>
     */
    public function findActiveMatchingAnyRole(array $anyOfRoles, array $excludedIds = [], ?string $search = null): array
    {
        return array_values(array_filter(
            $this->findActiveCandidates($excludedIds, $search),
            static fn (User $user): bool => [] !== array_intersect($anyOfRoles, $user->getRoles()),
        ));
    }

    // Roles are stored as a JSON column with no portable DQL way to query "array contains"
    // across DB engines, so the DB only filters what it can (inactiveDate, exclusion, search)
    // and role matching happens in PHP in the two methods above - fine at this scale (a school's
    // user roster, not millions of rows).
    /**
     * @param list<int> $excludedIds
     *
     * @return list<User>
     */
    private function findActiveCandidates(array $excludedIds, ?string $search): array
    {
        $qb = $this->createQueryBuilder('u')->where('u.inactiveDate IS NULL');

        if ([] !== $excludedIds) {
            $qb->andWhere('u.id NOT IN (:excludedIds)')->setParameter('excludedIds', $excludedIds);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('u.username LIKE :search OR CONCAT(u.firstname, \' \', u.lastname) LIKE :search OR u.email LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        $qb->orderBy('u.firstname', 'ASC')->addOrderBy('u.lastname', 'ASC')->addOrderBy('u.username', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
