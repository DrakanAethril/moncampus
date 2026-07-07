<?php

namespace App\Repository;

use App\Entity\Ticket;
use App\Entity\TicketComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicketComment>
 */
class TicketCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketComment::class);
    }

    // Reporters only ever see the public side of the thread; handlers (includeInternal=true)
    // see the full thread, internal notes included.
    /** @return list<TicketComment> */
    public function findForTicket(Ticket $ticket, bool $includeInternal): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->andWhere('c.ticket = :ticket')->setParameter('ticket', $ticket)
            ->orderBy('c.creationDate', 'ASC');

        if (!$includeInternal) {
            $qb->andWhere('c.visibility = :visibility')->setParameter('visibility', TicketComment::VISIBILITY_PUBLIC);
        }

        return $qb->getQuery()->getResult();
    }
}
