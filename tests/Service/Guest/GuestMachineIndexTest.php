<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Service\Guest\GuestMachineIndex;
use App\Service\Proxmox\ProxmoxGuest;
use PHPUnit\Framework\TestCase;

/**
 * The one difference this whole change rests on: **« the host said no » is not « the host said
 * nothing »**.
 *
 * Both callers of this class act on `isGone()` - one hides a card, the other deletes a row - so the
 * unreachable case is tested here rather than left to the screens. Getting it wrong would empty a
 * class's screens, or delete their accounts, on the day a hypervisor reboots.
 */
class GuestMachineIndexTest extends TestCase
{
    public function testAMachineTheHostStillHoldsIsNotGone(): void
    {
        $account = $this->account(1, 'pve1', 401);
        $index = new GuestMachineIndex(['1/401' => $this->guest(401, 'pve1')]);

        $this->assertFalse($index->isGone($account));
        $this->assertSame(401, $index->machineOf($account)?->vmid);
    }

    public function testAMachineAHostThatAnsweredDoesNotHoldIsGone(): void
    {
        $index = new GuestMachineIndex(['1/402' => $this->guest(402, 'pve1')]);

        $this->assertTrue($index->isGone($this->account(1, 'pve1', 401)));
    }

    /** The case the deletion path may never get wrong: not knowing is not knowing it is gone. */
    public function testAnUnreachableHostDecidesNothing(): void
    {
        $account = $this->account(1, 'pve1', 401);
        $index = new GuestMachineIndex([], [1 => true]);

        $this->assertFalse($index->isGone($account));
        $this->assertTrue($index->isUnanswered($account));
        $this->assertNull($index->machineOf($account));
    }

    /** A VMID is unique per cluster: a machine moved to another node is still the same machine. */
    public function testAMachineMigratedToAnotherNodeIsStillFound(): void
    {
        $account = $this->account(1, 'pve1', 401);
        $index = new GuestMachineIndex(['1/401' => $this->guest(401, 'pve2')]);

        $this->assertFalse($index->isGone($account));
        $this->assertSame('pve2', $index->machineOf($account)?->node);
    }

    /** Two hosts, two VMIDs that collide: the key carries the host, so neither answers for the other. */
    public function testTheSameVmidOnAnotherHostIsAnotherMachine(): void
    {
        $index = new GuestMachineIndex(['2/401' => $this->guest(401, 'pve1')]);

        $this->assertTrue($index->isGone($this->account(1, 'pve1', 401)));
        $this->assertFalse($index->isGone($this->account(2, 'pve1', 401)));
    }

    private function account(int $hostId, string $node, int $vmid): GuestAccount
    {
        $host = new ProxmoxHost('pve', 'pve.example.test', 'moncampus');
        (new \ReflectionProperty(ProxmoxHost::class, 'id'))->setValue($host, $hostId);

        return new GuestAccount($host, $node, $vmid, 'eleve');
    }

    private function guest(int $vmid, string $node): ProxmoxGuest
    {
        return new ProxmoxGuest($vmid, 'vm-'.$vmid, $node, ProxmoxGuest::TYPE_QEMU, 'running', false, null, 2, 0.1, 0, 0, 0, null, null);
    }
}
