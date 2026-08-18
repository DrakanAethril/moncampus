<?php

declare(strict_types=1);

namespace App\Tests\Service\Network;

use App\Enum\IpAllocationOrigin;
use App\Enum\IpAllocationStatus;
use App\Service\Network\AddressGap;
use App\Service\Network\AddressReconciler;
use App\Service\Network\DiscoveredAddress;
use App\Service\Network\RegisteredAddress;
use PHPUnit\Framework\TestCase;

/**
 * Crossing what the registry believes with what the hypervisor actually carries.
 *
 * The four gaps are four different problems, and the two cases worth guarding hardest are the ones
 * that must NOT be reported:
 *
 *   - **a reservation in flight is never orphaned.** A wizard somebody is halfway through holds an
 *     address that no machine carries yet, by definition. Calling that orphaned would invite an
 *     administrator to free an address about to be used, and hand the same one to two machines.
 *   - **an external entry is never orphaned either.** A printer is not a Proxmox guest, and the
 *     scan will never find one carrying its address however often it looks.
 *
 * Pure - no entities, no database - so the whole comparison can be exercised on plain values.
 */
class AddressReconcilerTest extends TestCase
{
    private function reconciler(): AddressReconciler
    {
        return new AddressReconciler();
    }

    private function guest(string $ip, int $vmid, string $name = 'srv', ?string $mac = null): DiscoveredAddress
    {
        return new DiscoveredAddress($ip, $vmid, 'pve1', $name, $mac, 'vmbr0', 20);
    }

    private function registered(
        string $ip,
        ?int $vmid = null,
        IpAllocationStatus $status = IpAllocationStatus::Assigned,
        IpAllocationOrigin $origin = IpAllocationOrigin::Declared,
    ): RegisteredAddress {
        return new RegisteredAddress($ip, $vmid, 'declared-'.$ip, $status, $origin);
    }

    /**
     * @param list<AddressGap> $gaps
     *
     * @return list<AddressGap>
     */
    private function ofKind(array $gaps, string $kind): array
    {
        return array_values(array_filter($gaps, static fn (AddressGap $gap): bool => $gap->kind === $kind));
    }

    public function testAnAgreeingRegistryProducesNoGap(): void
    {
        $gaps = $this->reconciler()->reconcile(
            [$this->registered('10.30.20.51', 201)],
            [$this->guest('10.30.20.51', 201)],
        );

        self::assertSame([], $gaps);
    }

    public function testTwoMachinesOnOneAddressIsAConflict(): void
    {
        $gaps = $this->ofKind($this->reconciler()->reconcile(
            [],
            [$this->guest('10.30.20.61', 207, 'pfsense-lab'), $this->guest('10.30.20.61', 238, 'srv-tp-11')],
        ), AddressGap::CONFLICT);

        self::assertCount(1, $gaps);
        self::assertSame('10.30.20.61', $gaps[0]->ip);
        self::assertCount(2, $gaps[0]->guests);
    }

    public function testAConflictIsReportedOnceHoweverManyMachinesShareTheAddress(): void
    {
        $gaps = $this->ofKind($this->reconciler()->reconcile([], [
            $this->guest('10.30.20.61', 207),
            $this->guest('10.30.20.61', 238),
            $this->guest('10.30.20.61', 244),
        ]), AddressGap::CONFLICT);

        self::assertCount(1, $gaps);
        self::assertCount(3, $gaps[0]->guests);
    }

    public function testAConflictIsNotAlsoReportedAsADiscovery(): void
    {
        // Two problems on one address would double the count on the card and make "4 gaps" a
        // number nobody can reconcile with the four lines under it.
        $gaps = $this->reconciler()->reconcile([], [
            $this->guest('10.30.20.61', 207),
            $this->guest('10.30.20.61', 238),
        ]);

        self::assertCount(1, $gaps);
        self::assertSame(AddressGap::CONFLICT, $gaps[0]->kind);
    }

    public function testAnAddressTheRegistryNeverHandedOutIsADiscovery(): void
    {
        $gaps = $this->ofKind(
            $this->reconciler()->reconcile([], [$this->guest('10.30.20.73', 219, 'srv-gitlab', 'BC:24:11:9F:03:2A')]),
            AddressGap::DISCOVERED,
        );

        self::assertCount(1, $gaps);
        self::assertSame('10.30.20.73', $gaps[0]->ip);
        self::assertSame(219, $gaps[0]->guests[0]->vmid);
    }

    public function testARegisteredAddressNobodyCarriesIsOrphaned(): void
    {
        // The only mechanism that ever notices a machine has been destroyed: the application does
        // not delete them, so it is never in that loop. Without the scan, a range never empties.
        $gaps = $this->ofKind(
            $this->reconciler()->reconcile([$this->registered('10.30.20.55', 209)], []),
            AddressGap::ORPHAN,
        );

        self::assertCount(1, $gaps);
        self::assertSame('10.30.20.55', $gaps[0]->ip);
        self::assertSame(209, $gaps[0]->registeredVmid);
    }

    public function testAReservationInFlightIsNeverOrphaned(): void
    {
        // The case that matters most: freeing this address would hand the same one to two machines.
        $gaps = $this->reconciler()->reconcile(
            [$this->registered('10.30.20.57', null, IpAllocationStatus::Reserved)],
            [],
        );

        self::assertSame([], $gaps, 'a creation in flight has no machine to find yet');
    }

    public function testAnExternalAddressIsNeverOrphaned(): void
    {
        $gaps = $this->reconciler()->reconcile(
            [$this->registered('10.30.20.90', null, IpAllocationStatus::Confirmed, IpAllocationOrigin::External)],
            [],
        );

        self::assertSame([], $gaps, 'a printer is not a Proxmox guest and never will be');
    }

    public function testAnExternalAddressThatAMachineStartsCarryingIsStillWorthReporting(): void
    {
        // Not orphaned - but a machine sitting on the printer's address is a genuine conflict, and
        // exempting external entries from the orphan check must not exempt them from this one.
        $gaps = $this->reconciler()->reconcile(
            [$this->registered('10.30.20.90', null, IpAllocationStatus::Confirmed, IpAllocationOrigin::External)],
            [$this->guest('10.30.20.90', 250, 'srv-new')],
        );

        self::assertCount(1, $gaps);
        self::assertSame(AddressGap::CONFLICT, $gaps[0]->kind);
    }

    public function testAnAddressCarriedByAnotherMachineHasMoved(): void
    {
        $gaps = $this->ofKind($this->reconciler()->reconcile(
            [$this->registered('10.30.20.51', 201)],
            [$this->guest('10.30.20.51', 260, 'srv-rebuilt')],
        ), AddressGap::MOVED);

        self::assertCount(1, $gaps);
        self::assertSame(201, $gaps[0]->registeredVmid);
        self::assertSame(260, $gaps[0]->guests[0]->vmid);
    }

    public function testARegisteredAddressWithNoRecordedMachineIsNotAMove(): void
    {
        // An adopted or hand-declared entry may legitimately carry no VMID; finding a machine on it
        // confirms it rather than contradicting it.
        $gaps = $this->reconciler()->reconcile(
            [$this->registered('10.30.20.73', null)],
            [$this->guest('10.30.20.73', 219)],
        );

        self::assertSame([], $gaps);
    }

    public function testTheFourKindsCoexistAndAreCountedOnce(): void
    {
        $gaps = $this->reconciler()->reconcile(
            [
                $this->registered('10.30.20.51', 201),   // agrees
                $this->registered('10.30.20.55', 209),   // orphan
                $this->registered('10.30.20.58', 215),   // moved
            ],
            [
                $this->guest('10.30.20.51', 201),
                $this->guest('10.30.20.58', 261),
                $this->guest('10.30.20.73', 219),        // discovered
                $this->guest('10.30.20.61', 207),        // conflict
                $this->guest('10.30.20.61', 238),
            ],
        );

        self::assertCount(4, $gaps);
        self::assertCount(1, $this->ofKind($gaps, AddressGap::CONFLICT));
        self::assertCount(1, $this->ofKind($gaps, AddressGap::DISCOVERED));
        self::assertCount(1, $this->ofKind($gaps, AddressGap::ORPHAN));
        self::assertCount(1, $this->ofKind($gaps, AddressGap::MOVED));
    }

    public function testGapsComeBackWithTheWorstFirst(): void
    {
        // The card is read top down and its first line should be the one that needs a person.
        $gaps = $this->reconciler()->reconcile(
            [$this->registered('10.30.20.55', 209)],
            [$this->guest('10.30.20.73', 219), $this->guest('10.30.20.61', 207), $this->guest('10.30.20.61', 238)],
        );

        self::assertSame(AddressGap::CONFLICT, $gaps[0]->kind);
    }

    public function testTheSameMachineListedTwiceOnOneAddressIsNotAConflict(): void
    {
        // A guest with two interfaces on the same network is odd but not a collision with anybody.
        $gaps = $this->reconciler()->reconcile([], [$this->guest('10.30.20.61', 207), $this->guest('10.30.20.61', 207)]);

        self::assertCount(1, $gaps);
        self::assertSame(AddressGap::DISCOVERED, $gaps[0]->kind);
    }
}
