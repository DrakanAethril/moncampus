<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * Opens a session into a machine with whichever platform key it still trusts.
 *
 * The "whichever" is the point. A rotation posts the new key, verifies it, and only then retires
 * the old one - so during that window a machine may accept either, and a machine that happened to
 * be switched off when the rotation ran accepts only the old one. Trying the keys in order (active
 * first) is how those machines are still reachable, and it is what makes
 * `app:proxmox:rotate-platform-key` safe to run on a fleet that is never entirely awake.
 *
 * The set is small on purpose, and has to stay small: every key is a full connection attempt, and
 * against a machine that does not answer each one is paid to the end of its budget. That is why
 * "usable" excludes retired keys - see PlatformSshKeyRepository::findUsable(). In the steady state
 * this loop runs once, and twice for the length of a rotation.
 *
 * The account MonCampus logs in as is the one cloud-init laid down. That is not a decision this
 * class makes - it is decided by the template - so it is a parameter and not a constant.
 */
class GuestShellFactory
{
    public function __construct(private readonly PlatformKeyProvider $keyProvider)
    {
    }

    /**
     * @throws PlatformKeyUnavailableException when no key exists at all
     * @throws GuestUnreachableException       when no key opens a session
     */
    public function open(string $ip, string $username = 'root', int $port = 22): GuestShell
    {
        $keys = $this->keyProvider->usableKeys();

        if ([] === $keys) {
            throw new PlatformKeyUnavailableException('No platform SSH key has been generated yet.');
        }

        $lastFailure = new GuestUnreachableException(\sprintf('No platform key opens a session on %s.', $ip));

        foreach ($keys as $key) {
            $session = new GuestSshSession($ip, $username, $this->keyProvider->privateKey($key), $port);

            try {
                // Cheapest possible proof that the key opens the door - and it also warms the
                // connection the caller is about to use.
                $session->run('true');

                return $session;
            } catch (GuestUnreachableException $exception) {
                $session->disconnect();
                $lastFailure = $exception;
            }
        }

        throw $lastFailure;
    }
}
