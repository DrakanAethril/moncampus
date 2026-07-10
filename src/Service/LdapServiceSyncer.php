<?php

namespace App\Service;

use App\Entity\LdapService;
use App\Entity\User;
use App\Repository\LdapServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Bulk-mirrors every LDAP service account into the local App\Entity\LdapService table -
 * Directory > Services' "sync now" action is the only way these rows ever get created, there's
 * no JIT equivalent (service accounts don't log into this app). Existing rows are never updated
 * or removed, same "only add the missing ones" contract as LdapUserSyncer/LdapGroupSyncer.
 *
 * Unlike users/groups/computers, "service account" has no canonical objectClass on either
 * directory flavor - it's purely a location convention (whatever lives directly under the
 * Services container). A Samba AD service account is typically an ordinary objectClass=user
 * entry, but this repo's own dev fixture (frankenphp/ldap/10-tree.local.ldif's svc-app) is
 * organizationalRole/simpleSecurityObject instead - confirmed empirically that assuming
 * $ldapUserObjectClass here missed it entirely. Matching everything except organizational units
 * (i.e. excluding the container itself, not requiring any particular class for the accounts in
 * it) is the one filter that works for both.
 */
class LdapServiceSyncer
{
    private const string SERVICE_SEARCH_FILTER = '(!(objectClass=organizationalUnit))';

    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly LdapServiceRepository $serviceRepository,
        private readonly string $ldapBaseDn,
        private readonly string $ldapServiceBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
    ) {
    }

    public function sync(User $actingUser): int
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $entries = $this->ldap->query($this->resolveServiceBaseDn(), self::SERVICE_SEARCH_FILTER)->execute();

        $existingNames = array_flip(array_map(
            static fn (LdapService $service): string => $service->getName(),
            $this->serviceRepository->findAll(),
        ));

        $createdCount = 0;
        foreach ($entries as $entry) {
            $name = ($entry->getAttribute('cn') ?? [])[0] ?? null;

            if (null === $name || isset($existingNames[$name])) {
                continue;
            }

            $description = ($entry->getAttribute('description') ?? [])[0] ?? null;

            $service = new LdapService($name, $description);
            $service->setCreatedBy($actingUser);
            $this->entityManager->persist($service);
            $existingNames[$name] = true;
            ++$createdCount;
        }

        $this->entityManager->flush();

        return $createdCount;
    }

    private function resolveServiceBaseDn(): string
    {
        return '' !== $this->ldapServiceBaseDn ? $this->ldapServiceBaseDn : $this->ldapBaseDn;
    }
}
