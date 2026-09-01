<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Repository\GuestAccountRepository;
use App\Service\Guest\GuestMachineIndex;
use App\Service\Guest\GuestMachineLocator;
use App\Service\Guest\StaleGuestAccountPruner;
use App\Service\Proxmox\ProxmoxGuest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * What the sweep removes, and - the part worth the test - what it refuses to remove.
 *
 * This is the only place in the application where a row is deleted on the strength of what a
 * hypervisor answered, so the two states that must never be confused are asserted from the outside:
 * a machine a host says it does not hold, and a machine no host answered about at all.
 */
class StaleGuestAccountPrunerTest extends TestCase
{
    public function testItRemovesOnlyTheAccountsOfMachinesTheHostNoLongerHolds(): void
    {
        $gone = $this->account(401);
        $alive = $this->account(402);
        $removed = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });
        $entityManager->expects($this->once())->method('flush');

        $report = $this->pruner(
            new GuestMachineIndex(['1/402' => $this->guest(402)]),
            $entityManager,
        )->prune([$gone, $alive]);

        $this->assertSame([$gone], $removed);
        $this->assertSame([$gone], $report->stale);
        $this->assertSame(1, $report->keptCount);
        $this->assertSame([], $report->undecided);
    }

    /** A hypervisor that is down is not a hypervisor that emptied itself. */
    public function testItRemovesNothingWhenTheHostDidNotAnswer(): void
    {
        $account = $this->account(401);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $report = $this->pruner(new GuestMachineIndex([], [1 => true]), $entityManager)->prune([$account]);

        $this->assertSame([], $report->stale);
        $this->assertSame([$account], $report->undecided);
    }

    /** Nothing to judge means nothing asked of any hypervisor. */
    public function testAnEmptyListAsksNothing(): void
    {
        $locator = $this->createMock(GuestMachineLocator::class);
        $locator->expects($this->never())->method('index');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $pruner = new StaleGuestAccountPruner($locator, $this->createStub(GuestAccountRepository::class), $entityManager);
        $report = $pruner->prune([]);

        $this->assertSame([], $report->stale);
        $this->assertSame(0, $report->keptCount);
    }

    private function pruner(GuestMachineIndex $index, EntityManagerInterface $entityManager): StaleGuestAccountPruner
    {
        $locator = $this->createStub(GuestMachineLocator::class);
        $locator->method('index')->willReturn($index);

        return new StaleGuestAccountPruner($locator, $this->createStub(GuestAccountRepository::class), $entityManager);
    }

    private function account(int $vmid): GuestAccount
    {
        $host = new ProxmoxHost('pve', 'pve.example.test', 'moncampus');
        (new \ReflectionProperty(ProxmoxHost::class, 'id'))->setValue($host, 1);

        return new GuestAccount($host, 'pve1', $vmid, 'eleve');
    }

    private function guest(int $vmid): ProxmoxGuest
    {
        return new ProxmoxGuest($vmid, 'vm-'.$vmid, 'pve1', ProxmoxGuest::TYPE_QEMU, 'running', false, null, 2, 0.1, 0, 0, 0, null, null);
    }
}
