<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameProfile;
use App\Entity\User;
use App\Repository\GameProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A student's durable profile, whether or not a row exists yet.
 *
 * for() answers a **transient** profile when nothing is stored - reading somebody's level must not
 * write a row - and persistent() is for the one gesture that changes it: stepping in or out of the
 * rankings.
 */
final class GameProfileProvider
{
    public function __construct(
        private readonly GameProfileRepository $profiles,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function for(User $student): GameProfile
    {
        return $this->profiles->findForStudent($student) ?? new GameProfile($student);
    }

    public function persistent(User $student): GameProfile
    {
        $profile = $this->profiles->findForStudent($student);

        if (null === $profile) {
            $profile = new GameProfile($student);
            $this->entityManager->persist($profile);
        }

        return $profile;
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }
}
