<?php

declare(strict_types=1);

namespace App\Tests\Service\Network;

use App\Service\Network\GuestAddressReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading a guest's addressing out of the flat option strings Proxmox stores.
 *
 * The payloads below are the real shapes, and the cases that matter are the *absences*: a QEMU
 * guest with no `ipconfig0` at all, one on `ip=dhcp`, an LXC whose address lives in `net0` next to
 * its bridge, and an interface carrying a VLAN tag. Each of those is an ordinary configuration in
 * a school's Proxmox, and treating any of them as a parse failure would make the scanner report a
 * machine as unaddressed when it is merely addressed differently.
 *
 * IPv6 appearing second in `ipconfig0` is included on purpose: the v4 address must still be read,
 * not lost because the string got longer.
 */
class GuestAddressReaderTest extends TestCase
{
    private function reader(): GuestAddressReader
    {
        return new GuestAddressReader();
    }

    public function testAQemuGuestWithAFixedAddress(): void
    {
        $address = $this->reader()->read([
            'name' => 'srv-tp-04',
            'net0' => 'virtio=02:4D:43:11:22:04,bridge=vmbr0',
            'ipconfig0' => 'ip=10.30.20.54/24,gw=10.30.20.1',
        ], 'qemu');

        self::assertSame('10.30.20.54', $address->ip);
        self::assertSame('24', $address->cidrSuffix);
        self::assertSame('10.30.20.1', $address->gateway);
        self::assertSame('02:4D:43:11:22:04', $address->macAddress);
        self::assertSame('vmbr0', $address->bridge);
        self::assertNull($address->vlan);
        self::assertFalse($address->dhcp);
        self::assertTrue($address->hasFixedAddress());
    }

    public function testAVlanTagIsRead(): void
    {
        // Without this, two ranges both in 10.30.x on different VLANs are indistinguishable, and
        // the registry invents conflicts.
        $address = $this->reader()->read([
            'net0' => 'virtio=02:4D:43:11:22:05,bridge=vmbr0,tag=30',
            'ipconfig0' => 'ip=10.30.20.55/24,gw=10.30.20.1',
        ], 'qemu');

        self::assertSame(30, $address->vlan);
        self::assertSame('vmbr0', $address->bridge);
    }

    public function testDhcpIsAnAnswerRatherThanAnAddress(): void
    {
        $address = $this->reader()->read(['net0' => 'virtio=02:4D:43:11:22:06,bridge=vmbr0', 'ipconfig0' => 'ip=dhcp'], 'qemu');

        self::assertTrue($address->dhcp);
        self::assertNull($address->ip);
        self::assertFalse($address->hasFixedAddress(), 'a machine on DHCP holds no address of this range');
    }

    public function testAGuestWithNoIpconfigAtAllStillYieldsItsInterface(): void
    {
        // The commonest case in a real fleet: a machine installed from an ISO, addressed by hand
        // inside the guest. Proxmox knows its MAC and its bridge and nothing else - and the
        // registry has to be able to say exactly that.
        $address = $this->reader()->read(['net0' => 'virtio=02:4D:43:00:09:00,bridge=vmbr0'], 'qemu');

        self::assertNull($address->ip);
        self::assertFalse($address->dhcp);
        self::assertSame('02:4D:43:00:09:00', $address->macAddress);
        self::assertSame('vmbr0', $address->bridge);
    }

    public function testAnLxcCarriesEverythingInNet0(): void
    {
        $address = $this->reader()->read([
            'hostname' => 'ct-web-01',
            'net0' => 'name=eth0,bridge=vmbr0,hwaddr=02:4D:43:11:22:10,ip=10.30.20.61/24,gw=10.30.20.1,tag=20',
        ], 'lxc');

        self::assertSame('10.30.20.61', $address->ip);
        self::assertSame('10.30.20.1', $address->gateway);
        self::assertSame('02:4D:43:11:22:10', $address->macAddress);
        self::assertSame('vmbr0', $address->bridge);
        self::assertSame(20, $address->vlan);
    }

    public function testAnLxcOnDhcp(): void
    {
        $address = $this->reader()->read(['net0' => 'name=eth0,bridge=vmbr0,hwaddr=BC:24:11:9F:03:2A,ip=dhcp'], 'lxc');

        self::assertTrue($address->dhcp);
        self::assertNull($address->ip);
        self::assertSame('BC:24:11:9F:03:2A', $address->macAddress);
    }

    public function testAnIpv6AddressInSecondPositionDoesNotHideTheV4One(): void
    {
        $address = $this->reader()->read([
            'net0' => 'virtio=02:4D:43:11:22:07,bridge=vmbr1',
            'ipconfig0' => 'ip=10.30.20.60/24,gw=10.30.20.1,ip6=2001:db8::7/64,gw6=2001:db8::1',
        ], 'qemu');

        self::assertSame('10.30.20.60', $address->ip);
        self::assertSame('10.30.20.1', $address->gateway);
    }

    public function testAConfigurationWithNoNetworkAtAllIsEmptyRatherThanBroken(): void
    {
        $address = $this->reader()->read(['name' => 'orphan', 'cores' => 2], 'qemu');

        self::assertNull($address->ip);
        self::assertNull($address->macAddress);
        self::assertNull($address->bridge);
        self::assertFalse($address->dhcp);
    }

    public function testTheHostnameIsReadFromWhicheverFieldCarriesIt(): void
    {
        $reader = $this->reader();

        self::assertSame('srv-tp-04', $reader->hostname(['name' => 'srv-tp-04'], 'qemu'));
        self::assertSame('ct-web-01', $reader->hostname(['hostname' => 'ct-web-01'], 'lxc'));
        self::assertNull($reader->hostname(['cores' => 2], 'qemu'));
    }

    public function testAMacIsNormalisedToUppercase(): void
    {
        // Proxmox writes them uppercase, but a hand-edited configuration may not, and the registry
        // compares them as strings.
        $address = $this->reader()->read(['net0' => 'virtio=02:4d:43:11:22:04,bridge=vmbr0'], 'qemu');

        self::assertSame('02:4D:43:11:22:04', $address->macAddress);
    }

    public function testValuesAreReadFromTheRightKeysEvenWhenTheOrderVaries(): void
    {
        // Proxmox does not promise an order inside these strings.
        $address = $this->reader()->read([
            'net0' => 'bridge=vmbr2,tag=42,virtio=02:4D:43:AA:BB:CC',
            'ipconfig0' => 'gw=192.168.5.1,ip=192.168.5.20/25',
        ], 'qemu');

        self::assertSame('192.168.5.20', $address->ip);
        self::assertSame('25', $address->cidrSuffix);
        self::assertSame('192.168.5.1', $address->gateway);
        self::assertSame('vmbr2', $address->bridge);
        self::assertSame(42, $address->vlan);
        self::assertSame('02:4D:43:AA:BB:CC', $address->macAddress);
    }
}
