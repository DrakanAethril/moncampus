<?php

namespace App\Repository;

use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipTutorLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipLivretEngagement>
 */
class InternshipLivretEngagementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipLivretEngagement::class);
    }

    public function findOneForTutorLink(InternshipTutorLink $tutorLink): ?InternshipLivretEngagement
    {
        return $this->findOneBy(['tutorLink' => $tutorLink]);
    }

    /**
     * "Mise à disposition du livret" waiting on the centre representative alone: the tutor and the
     * alternant have both signed, so AlternanceEngagementService::signAsCenter() will now accept
     * the third signature - the one that opens the evaluation periods. Powers the staff
     * dashboard's own banner, since nothing else surfaces this: the relances screen only walks
     * the per-period tutor/student steps, and this step can't even be e-mailed to anyone (see
     * AlternanceReminderService::emailForStep(), null for EngagementCenter).
     *
     * Inactive alternances are skipped, and the test fence is the strict either/or the UFA
     * dashboard uses rather than the "a real account sees everything" default - a staff member's
     * to-do banner is about real work.
     *
     * @return list<InternshipLivretEngagement>
     */
    public function findAllPendingCenterSignature(bool $testData = false): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('l', 'st', 'p')
            ->innerJoin('e.tutorLink', 'l')
            ->leftJoin('l.student', 'st')
            ->leftJoin('l.program', 'p')
            ->where('e.signedTutorAt IS NOT NULL')
            ->andWhere('e.signedStudentAt IS NOT NULL')
            ->andWhere('e.signedCenterAt IS NULL')
            ->andWhere('l.inactiveDate IS NULL')
            ->andWhere('l.testAlternance = :testData')
            ->setParameter('testData', $testData)
            ->orderBy('st.lastname', 'ASC')
            ->addOrderBy('st.firstname', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
