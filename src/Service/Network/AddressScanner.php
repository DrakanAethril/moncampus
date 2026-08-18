<?php

declare(strict_types=1);

namespace App\Service\Network;

use App\Entity\IpRange;
use App\Service\Proxmox\ProxmoxClient;
use App\Service\Proxmox\ProxmoxGuest;

/**
 * Reads back what every guest of a host is actually configured with.
 *
 * **`/cluster/resources` does not carry the IP.** It lists every machine of the cluster in one call
 * but says nothing about addressing, so learning where the addresses are means one `/config` call
 * per guest - twenty-eight of them on a modest host, each a full HTTPS round trip.
 *
 * Done one after another that is a scan nobody runs twice. Done the way it is here it is one wait:
 * `request()` on Symfony's HttpClient returns immediately and only *reading* a response blocks, so
 * every call is fired first and the answers are consumed as they arrive through `stream()`. The
 * cost becomes the slowest single call rather than the sum of all of them.
 *
 * A guest whose configuration cannot be read is skipped rather than fatal: one machine mid-migration
 * must not cost the whole scan, and a range that failed to scan is worse than a range scanned with
 * one hole - the reconciler would report every address as orphaned.
 */
class AddressScanner
{
    public function __construct(private readonly GuestAddressReader $reader)
    {
    }

    /**
     * Every fixed address configured on the host, whichever range it belongs to.
     *
     * @param list<ProxmoxGuest> $guests
     *
     * @return list<DiscoveredAddress>
     */
    public function scan(ProxmoxClient $client, array $guests): array
    {
        $machines = array_values(array_filter($guests, static fn (ProxmoxGuest $guest): bool => !$guest->template));

        $found = [];
        foreach ($client->configurations($machines) as $vmid => $config) {
            $guest = $this->guestWithVmid($machines, $vmid);

            if (null === $guest) {
                continue;
            }

            $address = $this->reader->read($config, $guest->type);

            // No fixed address is an answer, not a failure: a machine on DHCP, or one installed
            // from an ISO and addressed by hand inside the guest, holds nothing this range knows
            // about. In a fleet with no Windows template, that is half the machines.
            if (!$address->hasFixedAddress() || null === $address->ip) {
                continue;
            }

            $found[] = new DiscoveredAddress(
                $address->ip,
                $guest->vmid,
                $guest->node,
                $guest->name,
                $address->macAddress,
                $address->bridge,
                $address->vlan,
            );
        }

        return $found;
    }

    /**
     * Narrows a scan to one range. **Two criteria, not one**: the address has to fall inside the
     * CIDR *and* the interface has to sit on the declared bridge with the declared tag. Without the
     * second, two ranges both numbered 10.30.x on different VLANs are indistinguishable and the
     * registry starts reporting conflicts that do not exist.
     *
     * A guest whose bridge Proxmox did not report is kept: the address matches, and dropping it
     * would silently hide a machine rather than report it.
     *
     * @param list<DiscoveredAddress> $found
     *
     * @return list<DiscoveredAddress>
     */
    public function forRange(array $found, IpRange $range, IpRangeCalculator $calculator): array
    {
        return array_values(array_filter($found, function (DiscoveredAddress $address) use ($range, $calculator): bool {
            if (!$calculator->contains($range->getCidr(), $address->ip)) {
                return false;
            }

            if (null !== $address->bridge && $address->bridge !== $range->getBridge()) {
                return false;
            }

            return null === $address->vlan || $address->vlan === $range->getVlan();
        }));
    }

    /**
     * @param list<ProxmoxGuest> $guests
     */
    private function guestWithVmid(array $guests, int $vmid): ?ProxmoxGuest
    {
        foreach ($guests as $guest) {
            if ($guest->vmid === $vmid) {
                return $guest;
            }
        }

        return null;
    }
}
