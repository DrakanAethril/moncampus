<?php

namespace App\Repository;

use App\Entity\Program;
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

    // Powers messaging's candidate-recipient search and SchoolWide audience resolution (see
    // App\Service\MessagingAccessChecker/AudienceResolver) - same "DB filters what it can,
    // role matching happens in PHP" convention as findActiveMatchingAnyRole() above, just
    // inverted (keep everyone who does NOT hold the excluded role, e.g. ROLE_EXTERNAL).
    /**
     * @param list<int> $excludedIds
     *
     * @return list<User>
     */
    public function findActiveExcludingRole(string $excludedRole, array $excludedIds = [], ?string $search = null): array
    {
        return array_values(array_filter(
            $this->findActiveCandidates($excludedIds, $search),
            static fn (User $user): bool => !\in_array($excludedRole, $user->getRoles(), true),
        ));
    }

    // Resolves manually-submitted recipient ids back to Users - unlike
    // findByIdsForProgram(), not scoped to any one Program's roster, since messaging's manual
    // recipients can legally be any active user. The real security check happens one layer up
    // in App\Service\MessagingAccessChecker::resolveManualRecipients(), which re-validates every
    // id against the sender's permission matrix - this method only turns ids into User rows.
    /**
     * @param list<int> $ids
     *
     * @return list<User>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    // Powers the Assignment "manual recipients" select2 ajax search (see
    // App\Controller\ProgramAssignmentController::studentsSearch()) - deliberately queried on
    // demand rather than the caller loading Program::getStudents() and filtering in PHP, so a
    // program with a large roster never has its whole student list sent to the browser.
    //
    // Uses a two-root "u, p" FROM with MEMBER OF rather than `->innerJoin('p.students', 'u')`:
    // joining a collection-valued association like that makes Doctrine hydrate it as a
    // (query-filtered) sub-collection of the parent Program instead of returning flat User rows,
    // which isn't what a plain "give me a list of Users" query wants here.
    /** @return list<User> */
    public function searchStudentsForProgram(Program $program, ?string $search, int $limit): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->from(Program::class, 'p')
            ->where('p = :program')
            ->andWhere('u MEMBER OF p.students')
            ->setParameter('program', $program)
            ->orderBy('u.firstname', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
            ->setMaxResults($limit);

        if (null !== $search && '' !== $search) {
            $qb->andWhere('u.username LIKE :search OR CONCAT(u.firstname, \' \', u.lastname) LIKE :search OR u.email LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb->getQuery()->getResult();
    }

    // Resolves manually-submitted recipient ids back to Users, scoped to the program's actual
    // roster - both a security check (a forged id for a student in a different program is
    // silently dropped) and, like searchStudentsForProgram() above, avoids ever loading the
    // full roster just to validate a handful of submitted ids.
    /**
     * @param list<int> $ids
     *
     * @return list<User>
     */
    public function findByIdsForProgram(Program $program, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->from(Program::class, 'p')
            ->where('p = :program')
            ->andWhere('u MEMBER OF p.students')
            ->andWhere('u.id IN (:ids)')
            ->setParameter('program', $program)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
