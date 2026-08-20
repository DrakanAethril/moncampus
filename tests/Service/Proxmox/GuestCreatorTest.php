<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxmox;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Service\Guest\GuestAuthorizedKeys;
use App\Service\Network\GuestNetworkConfigurator;
use App\Service\Network\IpAllocator;
use App\Service\Proxmox\GuestCreationRequest;
use App\Service\Proxmox\GuestCreator;
use App\Service\Proxmox\ProxmoxClient;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxOperationTracker;
use App\Service\Proxmox\ProxmoxResponse;
use App\Service\Proxmox\ProxmoxScopeGuard;
use App\Service\Proxmox\ProxmoxUnavailableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * The creation path, tested where it meets the perimeter.
 *
 * This class exists because of a defect it would have caught: `ProxmoxScopeGuard` weighed the
 * "machines maximum" ceiling correctly and was thoroughly tested doing so, while its only caller
 * handed it a hard-coded `0` for the number of machines already there. The ceiling was stored,
 * displayed, translated - and inert, since `0 + 1` clears any ceiling above zero. A guard tested in
 * isolation proves nothing about the value it is given, which is the whole lesson here.
 *
 * So these tests are about the wiring rather than the arithmetic, and only the HTTP client is
 * imitated: the inventory and the guard are the real ones, reading rows shaped like the ones
 * `/cluster/resources` returns. What is asserted is that the count reaches the guard, that it is
 * the count *inside the perimeter*, and that a host declaring no ceiling is not made to pay for one.
 */
class GuestCreatorTest extends TestCase
{
    private function host(?int $maxGuests): ProxmoxHost
    {
        $host = new ProxmoxHost('campus', '192.0.2.10', 'svc');
        $host->setPort(8006)
            ->setSecretCipher('sealed')
            // canCreateGuests() is false without a second credential set, and the perimeter check
            // would then refuse before the quota is ever weighed - which would hide what is tested.
            ->setProvisionUsername('svc-provision')
            ->setProvisionSecretCipher('sealed')
            ->setAllowCreate(true)
            ->setManagedPool('moncampus')
            ->setVmidMin(200)
            ->setVmidMax(299)
            ->setMaxGuests($maxGuests);

        return $host;
    }

    private function range(): IpRange
    {
        $range = new IpRange('salle', $this->host(null), '10.30.0.0/24', '10.30.0.254', '10.30.0.1', '10.30.0.253');

        // Not part of the constructor, and a typed property with no default is uninitialized rather
        // than null - getBridge() raises before it is set.
        $range->setBridge('vmbr1')->setVlan(40);

        return $range;
    }

    private function request(): GuestCreationRequest
    {
        return new GuestCreationRequest(
            hostname: 'poste-01',
            vmid: 250,
            node: 'pve',
            cores: 2,
            memoryMib: 2048,
            diskGib: 32,
            storage: 'local-lvm',
            range: $this->range(),
            ip: '10.30.0.10',
            sourceVmid: 9001,
            linkedClone: true,
            isoVolumeId: null,
            startAfterCreation: false,
        );
    }

    /**
     * One row of `/cluster/resources?type=vm`, in the loose shape Proxmox actually sends.
     *
     * @return array<string, mixed>
     */
    private function row(int $vmid, ?string $pool, int $template = 0): array
    {
        return [
            'vmid' => $vmid,
            'name' => 'vm-'.$vmid,
            'node' => 'pve',
            'type' => 'qemu',
            'status' => 'running',
            'template' => $template,
            'pool' => $pool,
            'maxcpu' => 2,
            'cpu' => 0.0,
            'maxmem' => 2147483648,
            'mem' => 536870912,
            'maxdisk' => 34359738368,
            'uptime' => 3600,
        ];
    }

    private function creator(ProxmoxClient $client, ProxmoxOperationTracker $tracker, ?GuestAuthorizedKeys $authorizedKeys = null): GuestCreator
    {
        $factory = $this->createStub(ProxmoxClientFactory::class);
        $factory->method('provision')->willReturn($client);

        // A lock that grants itself: left to itself createLock() answers null, and the run would
        // die on $lock->acquire() before reaching anything this class is about.
        $lock = $this->createStub(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $locks = $this->createStub(LockFactory::class);
        $locks->method('createLock')->willReturn($lock);

        return new GuestCreator(
            $factory,
            new ProxmoxScopeGuard(),
            new ProxmoxInventory(),
            $tracker,
            $this->createStub(IpAllocator::class),
            // The real one: it is pure, and the payload it builds is precisely what the wiring
            // tests below are about.
            new GuestNetworkConfigurator(),
            $authorizedKeys ?? $this->authorizedKeys(),
            $locks,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function clientListing(array $rows): ProxmoxClient&MockObject
    {
        $client = $this->createMock(ProxmoxClient::class);
        $client->expects(self::once())
            ->method('get')
            ->with('/cluster/resources', ['type' => 'vm'])
            ->willReturn(ProxmoxResponse::fromData($rows));

        return $client;
    }

    public function testTheCeilingWeighsTheMachinesTheHypervisorActuallyHolds(): void
    {
        // The listing carries a template and a machine from another pool. Neither consumes a slot:
        // a template is an image, not a machine somebody was given, and another pool is another
        // perimeter. Two machines count, and the ceiling is two.
        $client = $this->clientListing([
            $this->row(201, 'moncampus'),
            $this->row(202, 'moncampus'),
            $this->row(9001, 'moncampus', template: 1),
            $this->row(203, 'infra'),
        ]);

        $tracker = $this->createMock(ProxmoxOperationTracker::class);

        // Nothing may be recorded: the refusal lands before the operation row is opened, so a
        // rejected creation leaves no trace of a machine that never existed.
        $tracker->expects(self::never())->method('begin');

        $this->expectException(ProxmoxUnavailableException::class);
        $this->expectExceptionMessage('proxmoxRefusalTooManyGuests');

        // Before the fix this went straight through: the count handed to the guard was always zero.
        $this->creator($client, $tracker)->create($this->host(2), $this->request(), new IpAllocation($this->range(), '10.30.0.10'), null);
    }

    public function testAHostBelowItsCeilingIsNotRefused(): void
    {
        $client = $this->clientListing([$this->row(201, 'moncampus')]);

        $tracker = $this->createMock(ProxmoxOperationTracker::class);

        // One machine inside the perimeter against a ceiling of two: the quota lets it through and
        // the run reaches the operation row. That is as far as this test follows it - what the
        // hypervisor then does with the clone is not the perimeter's business.
        $tracker->expects(self::once())->method('begin');

        try {
            $this->creator($client, $tracker)->create($this->host(2), $this->request(), new IpAllocation($this->range(), '10.30.0.10'), null);
        } catch (\Throwable) {
            // The imitated client answers nothing to the clone, which fails - after the quota.
        }
    }

    public function testConfiguringAMachineMergesItsCardInsteadOfRewritingIt(): void
    {
        // Same lesson as the ceiling above, one method further along: the merge itself is covered by
        // GuestNetworkConfiguratorTest, and would go on passing if this class stopped reading the
        // card and handing it over. So what is asserted here is the reading and the passing.
        $client = $this->createMock(ProxmoxClient::class);

        $client->expects(self::once())
            ->method('get')
            ->with('/nodes/pve/qemu/250/config')
            ->willReturn(ProxmoxResponse::fromData(['net0' => 'virtio=BC:24:11:66:51:BD,bridge=vmbr0,firewall=1,tag=300']));

        $client->expects(self::once())
            ->method('put')
            ->with('/nodes/pve/qemu/250/config', self::callback(static function (array $parameters): bool {
                // The range's bridge and VLAN win; the template's MAC and firewall flag survive.
                self::assertSame('virtio=BC:24:11:66:51:BD,bridge=vmbr1,firewall=1,tag=40', $parameters['net0']);
                self::assertSame('poste-01', $parameters['name']);

                return true;
            }))
            // ProxmoxResponse is final, so PHPUnit cannot invent one: the PUT's answer has to be
            // handed over explicitly.
            ->willReturn(ProxmoxResponse::fromData(null));

        $this->creator($client, $this->createStub(ProxmoxOperationTracker::class))
            ->configureAndStart($this->host(null), $this->request());
    }

    /**
     * The same lesson once more, and the reason this one is worth its lines: GuestAuthorizedKeysTest
     * settles *which* keys, and would go on passing if this class stopped asking for them at all.
     * What is asserted here is that it asks, and that what comes back reaches the payload.
     *
     * URL-encoded because Proxmox demands it of `sshkeys` - a key pasted raw is refused with
     * nothing useful said - which also means the newline between two keys travels as %0A.
     */
    public function testEveryAuthorizedKeyReachesTheMachineBeingConfigured(): void
    {
        $client = $this->createMock(ProxmoxClient::class);
        $client->method('get')->willReturn(ProxmoxResponse::fromData(['net0' => 'virtio,bridge=vmbr0']));

        $keys = $this->createStub(GuestAuthorizedKeys::class);
        $keys->method('forNewGuest')->willReturn("ssh-ed25519 AAAAplatform\nssh-ed25519 AAAAmarie");

        $client->expects(self::once())
            ->method('put')
            ->with('/nodes/pve/qemu/250/config', self::callback(static function (array $parameters): bool {
                self::assertSame(rawurlencode("ssh-ed25519 AAAAplatform\nssh-ed25519 AAAAmarie"), $parameters['sshkeys']);

                return true;
            }))
            ->willReturn(ProxmoxResponse::fromData(null));

        $this->creator($client, $this->createStub(ProxmoxOperationTracker::class), $keys)
            ->configureAndStart($this->host(null), $this->request());
    }

    public function testAHostWithNoCeilingNeverAsksTheHypervisorToCount(): void
    {
        // The reading costs a round trip to a hypervisor that may be slow or unreachable. A host
        // that declares no ceiling must not pay it, and must not fail to create because a listing
        // it never needed timed out.
        $client = $this->createMock(ProxmoxClient::class);
        $client->expects(self::never())->method('get');

        try {
            $this->creator($client, $this->createStub(ProxmoxOperationTracker::class))
                ->create($this->host(null), $this->request(), new IpAllocation($this->range(), '10.30.0.10'), null);
        } catch (\Throwable) {
        }
    }

    /**
     * Stubbed rather than real: what keys a machine is created with is GuestAuthorizedKeysTest's
     * subject, and this file is about the calls GuestCreator makes to the hypervisor.
     */
    private function authorizedKeys(): GuestAuthorizedKeys&Stub
    {
        $keys = $this->createStub(GuestAuthorizedKeys::class);
        $keys->method('forNewGuest')->willReturn('ssh-ed25519 AAAAplatform');

        return $keys;
    }
}
