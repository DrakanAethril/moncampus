<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SeriousGameAvatar;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeriousGameAvatar>
 */
class SeriousGameAvatarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeriousGameAvatar::class);
    }

    public function findOneForUser(User $user): ?SeriousGameAvatar
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * The avatars of several people at once, by user id - a class map draws one per row.
     *
     * @param list<User> $users
     *
     * @return array<int, SeriousGameAvatar>
     */
    public function findForUsers(array $users): array
    {
        if ([] === $users) {
            return [];
        }

        $out = [];
        foreach ($this->findBy(['user' => $users]) as $avatar) {
            $out[(int) $avatar->getUser()->getId()] = $avatar;
        }

        return $out;
    }
}
