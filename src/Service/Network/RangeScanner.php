<?php

declare(strict_types=1);

namespace App\Service\Network;

use App\Entity\IpRange;
use App\Repository\IpAllocationRepository;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One scan of one range: read the hypervisor, compare with the registry, report the gaps.
 *
 * This is the only mechanism that ever notices a machine has disappeared. **MonCampus does not
 * destroy machines**, so it is never in the loop when one is destroyed in Proxmox - the address
 * simply stops being carried, and nothing in the application would ever find out. Without a regular
 * scan a range never empties: it fills up with addresses no machine holds, and one day refuses to
 * hand out anything at all.
 *
 * The scan writes nothing to Proxmox and, on its own, nothing to the registry either. It reports.
 * Adopting a discovery or freeing an orphan is a decision an administrator takes on the screen,
 * because both are judgement calls: an orphan may be a machine somebody is about to restore from a
 * backup.
 */
class RangeScanner
{
    public function __construct(
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxInventory $inventory,
        private readonly AddressScanner $scanner,
        private readonly AddressReconciler $reconciler,
        private readonly IpAllocationRepository $allocations,
        private readonly IpRangeCalculator $calculator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function scan(IpRange $range): RangeScanReport
    {
        $host = $range->getHost();

        if (null === $host) {
            return RangeScanReport::failed('This range is attached to no host.');
        }

        try {
            $client = $this->clientFactory->operate($host);
            $guests = $this->inventory->machines($this->inventory->guests($client));
            $found = $this->scanner->forRange($this->scanner->scan($client, $guests), $range, $this->calculator);
        } catch (ProxmoxUnavailableException $exception) {
            // A failed scan leaves the last one standing: overwriting it with nothing would turn
            // every registered address into an orphan the moment the host went down for a reboot.
            return RangeScanReport::failed($exception->getMessage());
        }

        $registered = array_map(
            RegisteredAddress::fromAllocation(...),
            $this->allocations->findLive($range),
        );

        $range->setLastScanAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $taken = [
            ...array_map(static fn (RegisteredAddress $entry): string => $entry->ip, $registered),
            ...array_map(static fn (DiscoveredAddress $entry): string => $entry->ip, $found),
        ];

        return new RangeScanReport(
            $this->reconciler->reconcile($registered, $found),
            $found,
            \count($guests),
            $this->calculator->freeCount($range->getFirstUsable(), $range->getLastUsable(), $taken),
            $this->calculator->capacity($range->getFirstUsable(), $range->getLastUsable()),
        );
    }
}
