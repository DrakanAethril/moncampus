<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ExternalServicePassword;
use App\Entity\User;
use App\Enum\ExternalService;
use App\Enum\ExternalServiceSignIn;
use App\Repository\ExternalServicePasswordRepository;
use App\Repository\OAuthGrantRepository;
use App\Validator\StrongPassword;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The passwords people choose for external services, and the one rule that justifies them: **an
 * external service never gets the establishment password.**.
 *
 * The establishment password is the directory's; it opens the workstations, the Wi-Fi and the
 * internal resources. So a service outside the establishment - the Claude connector first - signs
 * its user in with a password of its own, chosen in « Mon profil ». Until one is chosen, the service
 * cannot be used at all. It must be strong (App\Validator\StrongPassword), must not contain the
 * username, and **must not be the establishment password** - which is checked against the directory
 * itself when it is chosen, and again at every sign-in, since the establishment password can later
 * be changed to it.
 *
 * Changing or removing a service password closes every connection opened with the old one: whoever
 * held it keeps nothing.
 *
 * Nothing here flushes; the caller owns its unit of work.
 */
final readonly class ExternalServicePasswords
{
    public const string HASHER = 'external_service';

    public function __construct(
        private ExternalServicePasswordRepository $passwords,
        private OAuthGrantRepository $grants,
        private PlatformPasswordCheck $platform,
        private PasswordHasherFactoryInterface $hashers,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function find(User $user, ExternalService $service): ?ExternalServicePassword
    {
        return $this->passwords->findFor($user, $service);
    }

    /**
     * @throws ExternalServicePasswordRefused
     */
    public function set(User $user, ExternalService $service, string $password): ExternalServicePassword
    {
        $violations = $this->validator->validate($password, new StrongPassword());
        if ($violations->count() > 0) {
            throw new ExternalServicePasswordRefused((string) $violations->get(0)->getMessageTemplate());
        }

        if (str_contains(mb_strtolower($password), mb_strtolower($user->getUsername()))) {
            throw new ExternalServicePasswordRefused('newPasswordContainsUsernameFlashMessage');
        }

        if ($this->isPlatformPassword($user, $password)) {
            throw new ExternalServicePasswordRefused('externalServicePasswordSameAsPlatformMessage');
        }

        $now = $this->clock->now();
        $hash = $this->hasher()->hash($password);
        $existing = $this->passwords->findFor($user, $service);

        if (null !== $existing) {
            $existing->replace($hash, $now);
            $this->closeConnections($user, $service);

            return $existing;
        }

        $created = new ExternalServicePassword($user, $service, $hash, $now);
        $this->entityManager->persist($created);

        return $created;
    }

    public function remove(User $user, ExternalService $service): void
    {
        $existing = $this->passwords->findFor($user, $service);

        if (null !== $existing) {
            $this->entityManager->remove($existing);
        }

        $this->closeConnections($user, $service);
    }

    /**
     * @throws PlatformPasswordCheckUnavailable
     */
    public function check(?User $user, ExternalService $service, string $password): ExternalServiceSignIn
    {
        $stored = null === $user ? null : $this->passwords->findFor($user, $service);

        if (null === $user || null !== $user->getInactiveDate()) {
            // A hash is computed all the same, so an unknown username costs what a known one does.
            $this->hasher()->verify('$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG', $password);

            return ExternalServiceSignIn::Refused;
        }

        if (null === $stored) {
            return ExternalServiceSignIn::NoServicePassword;
        }

        if (!$this->hasher()->verify($stored->getPasswordHash(), $password)) {
            return ExternalServiceSignIn::Refused;
        }

        if ($this->platform->isPlatformPassword($user, $password)) {
            return ExternalServiceSignIn::SameAsPlatformPassword;
        }

        $stored->markUsed($this->clock->now());

        return ExternalServiceSignIn::Accepted;
    }

    private function isPlatformPassword(User $user, string $password): bool
    {
        try {
            return $this->platform->isPlatformPassword($user, $password);
        } catch (PlatformPasswordCheckUnavailable) {
            throw new ExternalServicePasswordRefused('externalServicePasswordUncheckableMessage');
        }
    }

    /**
     * Every connection the service holds for this person - for the Claude connector, its OAuth
     * grants. A match, so a new service cannot be added without saying what closing means for it.
     */
    private function closeConnections(User $user, ExternalService $service): void
    {
        match ($service) {
            ExternalService::ClaudeConnector => $this->revokeGrants($user),
        };
    }

    private function revokeGrants(User $user): void
    {
        $now = $this->clock->now();
        foreach ($this->grants->findBy(['user' => $user, 'revokedAt' => null]) as $grant) {
            $grant->revoke($now);
        }
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->hashers->getPasswordHasher(self::HASHER);
    }
}
