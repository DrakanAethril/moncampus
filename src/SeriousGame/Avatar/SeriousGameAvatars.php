<?php

declare(strict_types=1);

namespace App\SeriousGame\Avatar;

use App\Entity\SeriousGameAvatar;
use App\Entity\User;
use App\Repository\SeriousGameAvatarRepository;
use App\Repository\SeriousGameRewardGrantRepository;
use App\Service\SeriousGame\SeriousGameRules;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes a person's avatar (design/validated/serious-games.md §7).
 *
 * The server **refuses a pin the person has not obtained**; an adult has an avatar to play with and
 * never a pin - pins are collection pieces, and only students collect.
 */
final readonly class SeriousGameAvatars
{
    public function __construct(
        private SeriousGameAvatarRepository $avatars,
        private SeriousGameRewardGrantRepository $grants,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> the person's config, version 2, defaults filled */
    public function configFor(User $user): array
    {
        $avatar = $this->avatars->findOneForUser($user);

        return AvatarConfig::normalize(null === $avatar ? [] : $avatar->getConfig(), $this->heldPins($user));
    }

    public function hasOne(User $user): bool
    {
        return null !== $this->avatars->findOneForUser($user);
    }

    /** @return list<string> */
    public function heldPins(User $user): array
    {
        return SeriousGameRules::canCollect($user->getRoles()) ? $this->grants->heldPins($user) : [];
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed> what was stored
     *
     * @throws \DomainException when the config asks for a pin the person does not hold
     */
    public function save(User $user, array $config): array
    {
        $held = $this->heldPins($user);
        if (AvatarConfig::asksForAPinNotHeld($config, $held)) {
            throw new \DomainException('seriousGameAvatarPinNotHeld');
        }

        $normalized = AvatarConfig::normalize($config, $held);
        $avatar = $this->avatars->findOneForUser($user);
        if (null === $avatar) {
            $this->entityManager->persist(new SeriousGameAvatar($user, $normalized, AvatarConfig::VERSION));
        } else {
            $avatar->setConfig($normalized, AvatarConfig::VERSION);
        }
        $this->entityManager->flush();

        return $normalized;
    }
}
