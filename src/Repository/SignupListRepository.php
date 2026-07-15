<?php

namespace App\Repository;

use App\Entity\SignupList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SignupList>
 */
class SignupListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SignupList::class);
    }

    // Unfiltered by audience - same "fine at this scale" convention as
    // AnnouncementRepository::findAllOrderedByDate(), narrowed per-user by
    // App\Security\Voter\SignupListVoter/AudienceTargetableVoter one layer up
    // (SignupListController::index()).
    /** @return list<SignupList> */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Candidates for the "attach an existing sign-up list" dropdown on AgendaEventType/
    // AnnouncementType/MessageComposeType - lists with no parent yet (a NOT EXISTS check against
    // each of the three possible parent tables, since the FK lives on the parent side - see
    // AgendaEvent::$signupList's docblock), plus $current itself so it stays selectable/visible
    // when editing a parent that's already attached to it. Scoped to $user's own lists unless
    // $includeAll (staff can attach anyone's list, a teacher only their own - same asymmetry as
    // App\Service\SignupListAccessChecker::allowedAudienceTypes()).
    /** @return list<SignupList> */
    public function findAvailableForAttachment(User $user, bool $includeAll, ?SignupList $current = null): array
    {
        $qb = $this->createQueryBuilder('s');

        $unattached = new Andx([
            'NOT EXISTS (SELECT 1 FROM App\Entity\AgendaEvent ae WHERE ae.signupList = s)',
            'NOT EXISTS (SELECT 1 FROM App\Entity\Announcement an WHERE an.signupList = s)',
            'NOT EXISTS (SELECT 1 FROM App\Entity\MessageThread mt WHERE mt.signupList = s)',
        ]);

        if (!$includeAll) {
            $unattached->add('s.createdBy = :user');
            $qb->setParameter('user', $user);
        }

        if (null !== $current) {
            $qb->where($qb->expr()->orX($unattached, 's = :current'))->setParameter('current', $current);
        } else {
            $qb->where($unattached);
        }

        return $qb->orderBy('s.creationDate', 'DESC')->getQuery()->getResult();
    }
}
