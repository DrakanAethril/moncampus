<?php

declare(strict_types=1);

namespace App\SeriousGame\Avatar;

use App\Entity\SeriousGameAvatar;
use App\Entity\User;
use App\Enum\AvatarChoice;
use App\Repository\SeriousGameAvatarRepository;
use App\Repository\SeriousGameRewardGrantRepository;
use App\Service\SeriousGame\SeriousGameRules;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes a person's drawn avatar (design/validated/serious-games.md §7) - every account's,
 * whatever the serious games module says: it is drawn on « Mon profil »
 * (design/validated/quiz-tournament.md §16) and the games always draw it, the default one standing
 * in for whoever never drew theirs.
 *
 * The server **refuses a pin the person has not obtained**; an adult has an avatar and never a pin -
 * pins are collection pieces, won in the games, and only students collect. Nothing is earned here.
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

    /**
     * The profile's « Enregistrer mon avatar »: the drawing is saved **and** becomes what stands
     * for the person across Formatool, in the same flush. Wearing a pin from the player page saves
     * the drawing too (save()) but chooses nothing - a student who shows their photo keeps it.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed> what was stored
     *
     * @throws \DomainException when the config asks for a pin the person does not hold
     */
    public function draw(User $user, array $config): array
    {
        // Refused before the choice moves, so that a refused drawing leaves nothing to flush.
        if (AvatarConfig::asksForAPinNotHeld($config, $this->heldPins($user))) {
            throw new \DomainException('seriousGameAvatarPinNotHeld');
        }
        $user->setAvatarChoice(AvatarChoice::Drawn);

        return $this->save($user, $config);
    }
}
