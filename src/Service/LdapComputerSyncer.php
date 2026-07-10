<?php

namespace App\Service;

use App\Entity\LdapComputer;
use App\Entity\User;
use App\Repository\LdapComputerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Bulk-mirrors every LDAP computer object into the local App\Entity\LdapComputer table -
 * Directory > Computers' "sync now" action is the only way these rows ever get created. Existing
 * rows are never updated or removed, same "only add the missing ones" contract as
 * LdapUserSyncer/LdapGroupSyncer/LdapServiceSyncer.
 *
 * The "computer" objectClass is an Active Directory schema concept with no OpenLDAP/RFC2307
 * equivalent (unlike the user/group object classes, which differ but both exist in some form on
 * either directory flavor) - hardcoded rather than made configurable, since there's no other
 * meaningful value to plug in for a directory that actually has domain-joined computers at all.
 * Dev's openldap container simply has none, so a sync there always finds zero, harmlessly.
 *
 * Scoped to $ldapComputerBaseDn when set, else falling back to $ldapBaseDn.
 */
class LdapComputerSyncer
{
    private const string COMPUTER_OBJECT_CLASS = 'computer';

    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly LdapComputerRepository $computerRepository,
        private readonly string $ldapBaseDn,
        private readonly string $ldapComputerBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
    ) {
    }

    public function sync(User $actingUser): int
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $entries = $this->ldap->query($this->resolveComputerBaseDn(), \sprintf('(objectClass=%s)', self::COMPUTER_OBJECT_CLASS))->execute();

        $existingNames = array_flip(array_map(
            static fn (LdapComputer $computer): string => $computer->getName(),
            $this->computerRepository->findAll(),
        ));

        $createdCount = 0;
        foreach ($entries as $entry) {
            $name = ($entry->getAttribute('cn') ?? [])[0] ?? null;

            if (null === $name || isset($existingNames[$name])) {
                continue;
            }

            $dnsHostName = ($entry->getAttribute('dNSHostName') ?? [])[0] ?? null;
            $operatingSystem = ($entry->getAttribute('operatingSystem') ?? [])[0] ?? null;

            $computer = new LdapComputer($name, $dnsHostName, $operatingSystem);
            $computer->setCreatedBy($actingUser);
            $this->entityManager->persist($computer);
            $existingNames[$name] = true;
            ++$createdCount;
        }

        $this->entityManager->flush();

        return $createdCount;
    }

    private function resolveComputerBaseDn(): string
    {
        return '' !== $this->ldapComputerBaseDn ? $this->ldapComputerBaseDn : $this->ldapBaseDn;
    }
}
