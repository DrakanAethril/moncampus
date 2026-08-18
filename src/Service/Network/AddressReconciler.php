<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * Crosses what the registry believes with what the hypervisor actually carries, and names the
 * disagreements.
 *
 * Pure: two arrays of plain values in, a list of gaps out. No entity, no database, no client - which
 * is what lets the whole comparison be exercised on paper before any of it is wired up.
 *
 * Two exemptions are load-bearing and are the reason this is not a three-line diff:
 *
 *  - **a reservation in flight is never orphaned.** A wizard somebody is halfway through holds an
 *    address no machine carries *yet*. Reporting it would invite an administrator to free an
 *    address about to be used, and hand the same one to two machines.
 *  - **an external entry is never orphaned.** A printer is not a Proxmox guest and the scan will
 *    never find one carrying its address, however often it looks.
 *
 * Neither exemption extends to conflicts: a machine sitting on the printer's address is a genuine
 * collision and gets reported as one.
 *
 * Each address yields at most one gap. Two problems on one address would double the count on the
 * card and make "4 gaps" a number nobody can reconcile with the lines beneath it.
 */
class AddressReconciler
{
    /**
     * @param list<RegisteredAddress> $registered what MonCampus believes
     * @param list<DiscoveredAddress> $discovered what the Proxmox scan found
     *
     * @return list<AddressGap> worst first - the card is read top down, and its first line should
     *                          be the one that needs a person
     */
    public function reconcile(array $registered, array $discovered): array
    {
        $registryByIp = [];
        foreach ($registered as $entry) {
            $registryByIp[$entry->ip] = $entry;
        }

        /** @var array<string, list<DiscoveredAddress>> $guestsByIp */
        $guestsByIp = [];
        foreach ($discovered as $guest) {
            $guestsByIp[$guest->ip][] = $guest;
        }

        $gaps = [];

        foreach ($guestsByIp as $ip => $guests) {
            $entry = $registryByIp[$ip] ?? null;

            // Distinct machines, not distinct rows: one guest reported twice (two interfaces on the
            // same network) is odd, but it is not colliding with anybody.
            $vmids = array_unique(array_map(static fn (DiscoveredAddress $guest): int => $guest->vmid, $guests));

            if (\count($vmids) > 1) {
                $gaps[] = new AddressGap(AddressGap::CONFLICT, (string) $ip, $guests, $entry?->vmid, $entry?->hostname);
                continue;
            }

            // An address held for something that is not a guest at all, with a guest now on it.
            if (null !== $entry && !$entry->expectsAGuest()) {
                $gaps[] = new AddressGap(AddressGap::CONFLICT, (string) $ip, $guests, $entry->vmid, $entry->hostname);
                continue;
            }

            if (null === $entry) {
                $gaps[] = new AddressGap(AddressGap::DISCOVERED, (string) $ip, $guests);
                continue;
            }

            // A registry entry with no recorded machine - adopted, or declared by hand - is
            // confirmed by finding one, not contradicted by it.
            if (null !== $entry->vmid && $entry->vmid !== $guests[0]->vmid) {
                $gaps[] = new AddressGap(AddressGap::MOVED, (string) $ip, $guests, $entry->vmid, $entry->hostname);
            }
        }

        foreach ($registered as $entry) {
            if (!isset($guestsByIp[$entry->ip]) && $entry->expectsAGuest()) {
                $gaps[] = new AddressGap(AddressGap::ORPHAN, $entry->ip, [], $entry->vmid, $entry->hostname);
            }
        }

        return $this->sorted($gaps);
    }

    /**
     * @param list<AddressGap> $gaps
     *
     * @return list<AddressGap>
     */
    private function sorted(array $gaps): array
    {
        $rank = [
            AddressGap::CONFLICT => 0,
            AddressGap::MOVED => 1,
            AddressGap::DISCOVERED => 2,
            AddressGap::ORPHAN => 3,
        ];

        usort($gaps, static fn (AddressGap $a, AddressGap $b): int => [$rank[$a->kind] ?? 9, $a->ip] <=> [$rank[$b->kind] ?? 9, $b->ip]);

        return $gaps;
    }
}
