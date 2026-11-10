<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Room;
use App\Entity\SeatingRoomLayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeatingRoomLayout>
 */
class SeatingRoomLayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeatingRoomLayout::class);
    }

    public function findForRoom(Room $room): ?SeatingRoomLayout
    {
        return $this->findOneBy(['room' => $room]);
    }

    /** @return list<SeatingRoomLayout> the rooms of the timetable still in use, by name */
    public function findForActiveRooms(): array
    {
        /** @var list<SeatingRoomLayout> $layouts */
        $layouts = $this->createQueryBuilder('layout')
            ->addSelect('room', 'author')
            ->join('layout.room', 'room')
            ->leftJoin('layout.updatedBy', 'author')
            ->where('room.inactiveDate IS NULL')
            ->orderBy('room.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $layouts;
    }
}
