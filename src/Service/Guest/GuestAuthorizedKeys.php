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
     * The keys to install, and who each of them belongs to.
     *
     * Both halves come out of the same reading on purpose: the descriptors end up in a machine's
     * installation log, where their whole value is being able to say « these are the keys that
     * machine actually got ». Read twice, they could disagree with what was written.
     */
    public function forNewGuest(): AuthorizedKeySet
    {
        $candidates = [];
        $owners = [];

        foreach ($this->repository->findAllWithOwners() as $key) {
            $owner = $key->getUser();
            $candidates[] = [
                'roles' => $owner->getRoles(),
                'active' => null === $owner->getInactiveDate(),
                'key' => $key->getPublicKey(),
            ];
            // Keyed by the material, because select() returns keys and not rows - it is a rule on
            // strings, and giving it entities to keep the labels would be the wrong trade.
            $owners[trim($key->getPublicKey())] = ($owner->getDisplayName() ?? $owner->getUsername()).' — '.$key->getLabel();
        }

        $platformKey = $this->keyProvider->publicKey();
        $keys = self::select($platformKey, $candidates);
        $descriptors = [];

        foreach ($keys as $key) {
            // The platform's own key has no owner row and no label: it is named for what it is.
            $descriptors[] = $owners[$key] ?? 'MonCampus';
        }

        return new AuthorizedKeySet([] === $keys ? null : implode("\n", $keys), $descriptors);
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
