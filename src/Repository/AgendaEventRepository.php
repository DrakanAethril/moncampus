<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgendaEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgendaEvent>
 */
class AgendaEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgendaEvent::class);
    }

    // Unfiltered by audience - same "fine at this scale" convention as
    // AnnouncementRepository::findAllActive(), narrowed per-user by
    // App\Security\Voter\AudienceTargetableVoter one layer up.
    //
    // That narrowing is precisely why `programs` is fetch-joined here (and in findPast() below).
    // Every one of the three callers - AgendaController, Api\AgendaController, HomeController's
    // dashboard card - immediately runs each row through AudienceTargetableVoter, which asks
    // App\Service\AudienceResolver, which reads `getPrograms()`. Left lazy, that collection
    // initialises once per event: the admin dashboard was measured issuing 66 single-row
    // `SELECT ... FROM program WHERE id = ?` against a database holding 9 Programs.
    //
    // Only this one collection is fetch-joined. `manualRecipients` is the other ManyToMany on the
    // entity and joining both in one DQL would multiply the two together into a cartesian product,
    // costing more rows than the lazy loads it saves; it is read for at most a handful of events
    // anyway. `signupList` is a ManyToOne, so it joins without that risk.
    //
    // The joins must stay LEFT: an inner join would silently drop every event that targets no
    // Program (audience type AllStudents/AllTeachers/AllStaff/Manual) instead of returning it with
    // an empty collection - see App\Tests\Service\AudienceResolverTest, which pins that shape.
    /** @return list<AgendaEvent> */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('p', 'sl')
            ->leftJoin('e.programs', 'p')
            ->leftJoin('e.signupList', 'sl')
            ->where('e.endAt >= :now OR (e.endAt IS NULL AND e.startAt >= :now)')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('e.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<AgendaEvent> */
    public function findPast(): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('p', 'sl')
            ->leftJoin('e.programs', 'p')
            ->leftJoin('e.signupList', 'sl')
            ->where('e.endAt < :now OR (e.endAt IS NULL AND e.startAt < :now)')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('e.startAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
