<?php

namespace App\Repository;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    /**
     * Roles whose holders are never offered as a recipient anywhere in the app: entreprise tutors
     * (ROLE_TUTOR, LDAP group "tutor") and the other outside accounts (ROLE_EXTERNAL, kept for
     * populations that are not tutors). Declared here once rather than re-listed by every caller -
     * messaging, announcements, agenda and signup lists all mean the same "not one of ours" set,
     * and one of them silently drifting from the others is exactly the bug this prevents.
     *
     * @var list<string>
     */
    public const array NON_ADDRESSABLE_ROLES = ['ROLE_TUTOR', 'ROLE_EXTERNAL'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
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

    // Powers messaging's candidate-recipient search (see App\Service\MessagingAccessChecker) -
    // same "DB filters what it can, role matching happens in PHP" convention as
    // findActiveMatchingAnyRole() above, just inverted (keep everyone who holds NONE of the
    // excluded roles, in practice self::NON_ADDRESSABLE_ROLES).
    /**
     * @param list<string> $excludedRoles
     * @param list<int>    $excludedIds
     *
     * @return list<User>
     */
    public function findActiveExcludingRoles(array $excludedRoles, array $excludedIds = [], ?string $search = null): array
    {
        return array_values(array_filter(
            $this->findActiveCandidates($excludedIds, $search),
            static fn (User $user): bool => [] === array_intersect($excludedRoles, $user->getRoles()),
        ));
    }

    // Backs the tom-select ajax widget for the Directory > Mots de passe request picker (see
    // App\Controller\DirectoryPasswordController::userSearch()) - any active user can have their
    // LDAP password reset, no role restriction, hence no reuse of findActiveMatchingAnyRole/
    // findActiveExcludingRole above (both are role-scoped candidate lists for a different use
    // case). Limited server-side like searchStudentsForProgram()/MessagingAccessChecker's search,
    // never the full roster.
    /** @return list<User> */
    public function searchActive(?string $search, int $limit): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.inactiveDate IS NULL')
            ->orderBy('u.firstname', 'ASC')
            ->addOrderBy('u.lastname', 'ASC')
            ->addOrderBy('u.username', 'ASC')
            ->setMaxResults($limit);
        $this->applyListingSearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    // Backs the "Rechercher un tuteur existant" tom-select ajax field on the alternance forms
    // (32a/32b, see App\Controller\Ufa\AlternanceController::tutorSearch()). Searches tutor
    // ACCOUNTS, deliberately NOT the alternances they already hold the way
    // InternshipTutorLinkRepository::searchDistinctTutors() does for the Tuteurs annuaire (26b):
    // a tutor created from Annuaire > Utilisateurs with userType "tutor" - which is how a test
    // tutor gets set up before any alternance exists - has no link to be found through, so the
    // link-based search made exactly the accounts this picker exists to attach unreachable.
    //
    // The alternances are still joined, but only so a tutor stays findable by the name of the
    // entreprise shown beside them in the dropdown; a tutor with no alternance at all matches on
    // their own identity fields alone.
    //
    // Same asymmetry as everywhere else (App\Security\StructureAccessChecker::matchesTestMode()):
    // a test account is confined to test tutors, a real one keeps the whole directory - including
    // test tutors, which is what makes "alternance de test + tuteur de test" possible for the
    // staff member who set both up.
    /** @return list<User> */
    public function searchTutors(?string $search, int $limit, ?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u')
            ->distinct()
            ->leftJoin(InternshipTutorLink::class, 'l', Join::WITH, 'l.tutor = u')
            ->leftJoin('l.enterprise', 'e')
            ->where('u.inactiveDate IS NULL')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC');

        if (true === $viewer?->isTestUser()) {
            $qb->andWhere('u.testUser = true');
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('u.firstname LIKE :search OR u.lastname LIKE :search OR u.contactEmail LIKE :search OR e.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        // Roles are a JSON column with no portable DQL "array contains", so the role match happens
        // in PHP - hence the limit applied here rather than as setMaxResults(), which would slice
        // the roster before the tutors were picked out of it. Same convention (and same "a school's
        // roster, not millions of rows" reasoning) as findActiveCandidates() above.
        $tutors = array_values(array_filter(
            $qb->getQuery()->getResult(),
            static fn (User $user): bool => \in_array('ROLE_TUTOR', $user->getRoles(), true),
        ));

        return \array_slice($tutors, 0, $limit);
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
