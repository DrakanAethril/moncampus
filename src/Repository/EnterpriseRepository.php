<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enterprise;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enterprise>
 */
class EnterpriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enterprise::class);
    }

    // Powers InternshipTutorLinkType's "pick an existing enterprise" dropdown.
    /** @return list<Enterprise> */
    public function findAllActiveOrderedByName(?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.inactiveDate IS NULL')
            ->orderBy('e.name', 'ASC');

        // Same asymmetry as ProgramRepository::findAlternanceForSchoolYear(): a test account is
        // confined to test employers, a real one keeps seeing every one of them - somebody has to
        // be able to set a test alternance up in the first place. $viewer is optional so a caller
        // with no user in hand keeps the unfiltered list rather than silently getting one view.
        if ($viewer?->isTestUser()) {
            $qb->andWhere('e.testEnterprise = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * « UFA > Entreprises » - one page of employers, most recently created first.
     *
     * The order is on the creation date rather than the name because the screen opens on « les 20
     * plus récentes »: an employer is typed in as a contract arrives, so the one somebody is
     * looking for right after an import is at the top without searching for it. The search is on
     * the name alone - it is the only thing the list shows, and a match on an address nobody can
     * see reads as a bug.
     *
     * @return list<Enterprise>
     */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, ?User $viewer = null): array
    {
        return $this->queryMatching($search, $viewer)
            ->orderBy('e.creationDate', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countMatching(?string $search = null, ?User $viewer = null): int
    {
        return (int) $this->queryMatching($search, $viewer)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One employer as the given reader may see it - a test account is confined to test employers
     * here as everywhere else (App\Security\StructureAccessChecker::matchesTestMode()); the other
     * way round is open, somebody has to set the test world up.
     */
    public function findVisible(int $id, User $viewer): ?Enterprise
    {
        $enterprise = $this->find($id);

        if (null === $enterprise || ($viewer->isTestUser() && !$enterprise->isTestEnterprise())) {
            return null;
        }

        return $enterprise;
    }

    /**
     * « À confirmer » - **the one definition** the SIRET queue, its counter and its « suivante »
     * all read (design/validated/siret-entreprises.md, §4.2): an active employer the reader may see,
     * whose SIRET nobody has confirmed, and which is not set aside - or whose set-aside has run out.
     * Enterprise::siretStatus() makes the same reading on one row, and must agree with this one.
     */
    public function queryPendingSiret(\DateTimeImmutable $now, ?User $viewer): QueryBuilder
    {
        return $this->queryMatching(null, $viewer)
            ->andWhere('e.siretConfirmedAt IS NULL')
            ->andWhere('e.siretNotFoundAt IS NULL OR e.siretNotFoundAt <= :setAsideCutoff')
            ->setParameter('setAsideCutoff', $now->modify(\sprintf('-%d days', Enterprise::SIRET_SET_ASIDE_DAYS)));
    }

    public function countPendingSiret(\DateTimeImmutable $now, ?User $viewer): int
    {
        return (int) $this->queryPendingSiret($now, $viewer)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** How many are ahead of this id in the queue - its position, less one. */
    public function countPendingSiretBefore(int $id, \DateTimeImmutable $now, ?User $viewer): int
    {
        return (int) $this->queryPendingSiret($now, $viewer)
            ->select('COUNT(e.id)')
            ->andWhere('e.id < :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** The next employer of the queue, in id order, after the given one (0 for the first). */
    public function findNextPendingSiret(int $afterId, \DateTimeImmutable $now, ?User $viewer): ?Enterprise
    {
        $next = $this->queryPendingSiret($now, $viewer)
            ->andWhere('e.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $next instanceof Enterprise ? $next : null;
    }

    /**
     * The other fiches already carrying one of these SIRETs, by SIRET (R8) - active or not, since a
     * duplicate is a duplicate either way; within what the reader may see.
     *
     * @param list<string> $sirets
     *
     * @return array<string, Enterprise>
     */
    public function findOthersBySiret(array $sirets, ?Enterprise $except, ?User $viewer): array
    {
        if ([] === $sirets) {
            return [];
        }

        $qb = $this->createQueryBuilder('e')
            ->where('e.siret IN (:sirets)')
            ->setParameter('sirets', $sirets)
            ->orderBy('e.id', 'ASC');

        if (null !== $except?->getId()) {
            $qb->andWhere('e.id <> :except')->setParameter('except', $except->getId());
        }
        if ($viewer?->isTestUser()) {
            $qb->andWhere('e.testEnterprise = true');
        }

        $bySiret = [];
        /** @var list<Enterprise> $others */
        $others = $qb->getQuery()->getResult();
        foreach ($others as $other) {
            $siret = (string) $other->getSiret();
            $bySiret[$siret] ??= $other;
        }

        return $bySiret;
    }

    /**
     * The employers of the vivier whose **confirmed** SIRET is one of these - how a line of the
     * register is recognised (design/validated/vivier-entreprises.md, R8). An unconfirmed number
     * proves nothing, so it recognises nothing.
     *
     * @param list<string> $sirets
     *
     * @return array<string, Enterprise> keyed by SIRET
     */
    public function findConfirmedBySirets(array $sirets, ?User $viewer): array
    {
        if ([] === $sirets) {
            return [];
        }

        $found = [];
        foreach ($this->queryMatching(null, $viewer)
            ->andWhere('e.siret IN (:sirets)')
            ->andWhere('e.siretConfirmedAt IS NOT NULL')
            ->setParameter('sirets', array_values(array_unique($sirets)))
            ->getQuery()
            ->getResult() as $enterprise) {
            $found[(string) $enterprise->getSiret()] = $enterprise;
        }

        return $found;
    }

    /**
     * The employers of the vivier with a confirmed SIRET inside one of these companies (SIREN, the
     * nine first digits) - « stage dans un autre établissement de l'entreprise ».
     *
     * @param list<string> $sirens
     *
     * @return list<Enterprise>
     */
    public function findConfirmedBySirens(array $sirens, ?User $viewer): array
    {
        if ([] === $sirens) {
            return [];
        }

        return $this->queryMatching(null, $viewer)
            ->andWhere('SUBSTRING(e.siret, 1, 9) IN (:sirens)')
            ->andWhere('e.siretConfirmedAt IS NOT NULL')
            ->setParameter('sirens', array_values(array_unique($sirens)))
            ->getQuery()
            ->getResult();
    }

    /** Any employer already carrying this number, confirmed or not - so one SIRET is never two fiches. */
    public function findOneBySiret(string $siret, ?User $viewer): ?Enterprise
    {
        $enterprise = $this->queryMatching(null, $viewer)
            ->andWhere('e.siret = :siret')
            ->setParameter('siret', $siret)
            ->orderBy('e.siretConfirmedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $enterprise instanceof Enterprise ? $enterprise : null;
    }

    /**
     * The rows both of the above read, before either the paging or the counting: same WHERE on both
     * sides, so the total can never describe a different set from the page underneath it.
     */
    // Named queryMatching() rather than matching(): Doctrine's own EntityRepository::matching()
    // is the public Criteria API, and a private override of it is a fatal error at class load.
    private function queryMatching(?string $search, ?User $viewer): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.inactiveDate IS NULL');

        // Same asymmetry as findAllActiveOrderedByName() above.
        if ($viewer?->isTestUser()) {
            $qb->andWhere('e.testEnterprise = true');
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('e.name LIKE :search')->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }
}
