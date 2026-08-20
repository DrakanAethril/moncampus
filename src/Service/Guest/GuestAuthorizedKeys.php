<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Repository\UserSshKeyRepository;

/**
 * The set of public keys a machine is created with: the platform's own, then those of every active
 * administrator.
 *
 * **Read at creation, never stored.** A machine carries the keys of whoever was an administrator on
 * the day it was made, and nothing here goes back to change a machine afterwards - adding a key
 * does not reach the machines that already exist, and neither does losing the role. That is a
 * consequence worth stating rather than a limitation to work around: the alternative is an
 * application that rewrites authorized_keys on a fleet behind its administrators' backs.
 *
 * The platform key is first and unconditional. It is the one MonCampus itself logs in with - the
 * accounts screen, the batch deployment, the rotation command all depend on it - so a machine
 * created without it is a machine this application cannot reach again.
 *
 * Who counts as an administrator is asked of User::getRoles(), which merges what LDAP grants with
 * the groups an administrator set by hand. See UserSshKeyRepository::findAllWithOwners() for why
 * that question is not put to the database.
 */
class GuestAuthorizedKeys
{
    public function __construct(
        private readonly PlatformKeyProvider $keyProvider,
        private readonly UserSshKeyRepository $repository,
    ) {
    }

    /**
     * The keys as cloud-init wants them, one per line, or null when there are none at all - which
     * is what the configurator reads to leave the parameter out entirely.
     */
    public function forNewGuest(): ?string
    {
        $candidates = [];

        foreach ($this->repository->findAllWithOwners() as $key) {
            $owner = $key->getUser();
            $candidates[] = [
                'roles' => $owner->getRoles(),
                'active' => null === $owner->getInactiveDate(),
                'key' => $key->getPublicKey(),
            ];
        }

        $keys = self::select($this->keyProvider->publicKey(), $candidates);

        return [] === $keys ? null : implode("\n", $keys);
    }

    /**
     * The rule itself, on primitives: an administrator, still active, and no line twice.
     *
     * @param list<array{roles: list<string>, active: bool, key: string}> $candidates
     *
     * @return list<string>
     */
    public static function select(?string $platformKey, array $candidates): array
    {
        $keys = [];

        if (null !== $platformKey && '' !== trim($platformKey)) {
            $keys[] = trim($platformKey);
        }

        foreach ($candidates as $candidate) {
            if (!$candidate['active'] || !\in_array('ROLE_ADMIN', $candidate['roles'], true)) {
                continue;
            }

            $key = trim($candidate['key']);

            if ('' !== $key && !\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
