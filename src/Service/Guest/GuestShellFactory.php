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
 *
 * **A key that is accepted is not a session that works.** Cloud images routinely put the keys into
 * root's authorized_keys behind a forced command that prints "log in as debian instead" and exits;
 * the login then succeeds and nothing runs. Every session opened here is proved with
 * App\Service\Guest\GuestShellProbe before it is handed over, so that state is an error naming
 * the machine's own answer rather than a silent hour.
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
                // Not `true`: a session can open and still run nothing at all. The marker is the
                // proof - see App\Service\Guest\GuestShellProbe.
                $result = $session->run(GuestShellProbe::command());
            } catch (GuestUnreachableException $exception) {
                $session->disconnect();
                $lastFailure = $exception;

                continue;
            }

            if (GuestShellProbe::provesCommandsRun($result->output)) {
                return $session;
            }

            // Thrown out of the loop rather than treated as a key that did not fit: the door opened,
            // so another key cannot help. Trying them all would only pay the ten-second sleep of a
            // forced command once per key.
            $session->disconnect();

            throw new GuestUnreachableException(\sprintf(
                '%s@%s accepted the platform key but runs no commands - %s',
                $username,
                $ip,
                GuestShellProbe::describe($result->output),
            ));
        }

        throw $lastFailure;
    }
}
