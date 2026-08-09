<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternshipReminder;
use App\Entity\InternshipTutorLink;
use App\Enum\AlternanceReminderStep;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipReminder>
 */
class InternshipReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipReminder::class);
    }

    // Powers the 34a "Dernière relance le ..." caption and the 34c "Relances déjà envoyées" history
    // list - most recent first.
    /** @return list<InternshipReminder> */
    public function findAllForTutorLinkOrderedByMostRecent(InternshipTutorLink $tutorLink): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('sb')
            ->leftJoin('r.sentBy', 'sb')
            ->where('r.tutorLink = :tutorLink')
            ->setParameter('tutorLink', $tutorLink)
            ->orderBy('r.sentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findMostRecentForTutorLinkAndStep(InternshipTutorLink $tutorLink, AlternanceReminderStep $step): ?InternshipReminder
    {
        return $this->createQueryBuilder('r')
            ->where('r.tutorLink = :tutorLink')
            ->andWhere('r.step = :step')
            ->setParameter('tutorLink', $tutorLink)
            ->setParameter('step', $step)
            ->orderBy('r.sentAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
