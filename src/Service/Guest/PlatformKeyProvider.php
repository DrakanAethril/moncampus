<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\PlatformSshKey;
use App\Entity\User;
use App\Repository\PlatformSshKeyRepository;
use App\Service\Crypto\SecretBoxException;
use App\Service\Crypto\SecretBoxProvider;
use Doctrine\ORM\EntityManagerInterface;
use phpseclib3\Crypt\Common\AsymmetricKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * The key pair that gets MonCampus inside the machines it created, and the only place its private
 * half is ever decrypted.
 *
 * Ed25519 rather than RSA: the public half is short enough to travel comfortably in a cloud-init
 * `sshkeys` parameter, and every target here is a recent Linux.
 *
 * Generating is deliberately **not** implicit. A key that appeared on its own the first time
 * something needed one would be a key nobody knows the age of, laid into machines nobody chose to
 * lay it into - so the screen asks, and `app:proxmox:rotate-platform-key` does it explicitly.
 */
class PlatformKeyProvider
{
    public function __construct(
        private readonly PlatformSshKeyRepository $repository,
        private readonly SecretBoxProvider $secretBoxProvider,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function activeKey(): ?PlatformSshKey
    {
        return $this->repository->findActive();
    }

    /** What goes into a machine: `authorized_keys`, or cloud-init's `sshkeys`. */
    public function publicKey(): ?string
    {
        return $this->activeKey()?->getPublicKey();
    }

    /**
     * The private half, in PEM, for one SSH session.
     *
     * The only decryption of this material in the repository. Nothing caches the result, nothing
     * writes it to disk, and no route renders it.
     *
     * @throws PlatformKeyUnavailableException when there is no key, or it cannot be decrypted
     */
    public function privateKey(?PlatformSshKey $key = null): string
    {
        $key ??= $this->activeKey();

        if (null === $key) {
            throw new PlatformKeyUnavailableException('No platform SSH key has been generated yet.');
        }

        try {
            return $this->secretBoxProvider->get()->open($key->getPrivateKeyCipher());
        } catch (SecretBoxException $exception) {
            throw new PlatformKeyUnavailableException('The platform SSH key could not be decrypted - check PROXMOX_SECRET_KEY.', previous: $exception);
        }
    }

    /**
     * Every key a machine might still trust, active one first.
     *
     * A rotation posts the new key, verifies it, and only then retires the old - so during that
     * window a machine may accept either, and a machine that was offline when the rotation started
     * still accepts only the old one. Trying them in order is how those are reached.
     *
     * @return list<PlatformSshKey>
     */
    public function usableKeys(): array
    {
        return $this->repository->findUsable();
    }

    /**
     * Generates a new key and makes it the active one, **without retiring the previous**.
     *
     * That is the whole safety of a rotation and the order must not be reversed: retiring first
     * would lock MonCampus out of every machine that has not yet received the new key. Retiring is
     * a separate, deliberate step - see retire().
     *
     * @throws PlatformKeyUnavailableException when encryption is unavailable
     */
    public function generate(?User $createdBy = null): PlatformSshKey
    {
        if (!$this->secretBoxProvider->isAvailable()) {
            throw new PlatformKeyUnavailableException('Encryption is unavailable: a platform key cannot be stored.');
        }

        $private = EC::createKey('Ed25519');
        $privatePem = (string) $private->toString('OpenSSH', ['comment' => 'moncampus@platform']);

        // getPublicKey() is declared loosely because every curve answers a different class -
        // narrowed here rather than cast, so a phpseclib change surfaces as an exception at
        // generation time instead of a fatal much later, inside a session.
        $public = $private->getPublicKey();

        if (!$public instanceof AsymmetricKey) {
            throw new PlatformKeyUnavailableException('The generated key has no exportable public half.');
        }

        $publicKey = trim((string) $public->toString('OpenSSH', ['comment' => 'moncampus@platform']));

        $key = new PlatformSshKey(
            $publicKey,
            $this->secretBoxProvider->get()->seal($privatePem),
            hash('sha256', $publicKey),
        );
        $key->setCreatedBy($createdBy);

        $this->entityManager->persist($key);
        $this->entityManager->flush();

        return $key;
    }

    /** Ends a rotation. Called only once the new key has been verified on the machines that matter. */
    public function retire(PlatformSshKey $key): void
    {
        $key->retire();
        $this->entityManager->flush();
    }

    /** Whether a private key parses - a cheap guard before a session is attempted. */
    public function isUsable(PlatformSshKey $key): bool
    {
        try {
            PublicKeyLoader::load($this->privateKey($key));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
