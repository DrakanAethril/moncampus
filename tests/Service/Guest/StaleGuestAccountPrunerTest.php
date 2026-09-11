<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Entity\VmBatch;
use App\Repository\GuestAccountRepository;
use App\Repository\VmBatchItemRepository;
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

    /**
     * The failure this whole rule exists for: a VMID Proxmox handed back and a later batch took.
     *
     * The hypervisor answers « 9002 is here » about a machine that has nothing to do with the old
     * batch's accounts, so the gone-machine rule cannot see it. Left in place those rows are read as
     * current and a deployment creates a former class's students on the new machine.
     */
    public function testItRemovesTheAccountsOfABatchWhoseNumberAnotherBatchNowHolds(): void
    {
        $previous = $this->account(9002, batchId: 7);
        $current = $this->account(9002, batchId: 9);
        $removed = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $report = $this->pruner(
            new GuestMachineIndex(['1/9002' => $this->guest(9002)]),
            $entityManager,
            [1 => [9002 => 9]],
        )->prune([$previous, $current]);

        $this->assertSame([$previous], $removed);
        $this->assertSame([$previous], $report->stale);
        $this->assertSame(1, $report->keptCount);
    }

    /** An account filed under no batch names no deployment, so nothing about it is decidable. */
    public function testItLeavesAnAccountWithoutABatchAlone(): void
    {
        $account = $this->account(9002);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $report = $this->pruner(
            new GuestMachineIndex(['1/9002' => $this->guest(9002)]),
            $entityManager,
            [1 => [9002 => 9]],
        )->prune([$account]);

        $this->assertSame([], $report->stale);
        $this->assertSame(1, $report->keptCount);
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

        $items = $this->createMock(VmBatchItemRepository::class);
        $items->expects($this->never())->method('findDeployedBatchIdsByHostAndVmid');

        $pruner = new StaleGuestAccountPruner($locator, $this->createStub(GuestAccountRepository::class), $items, $entityManager);
        $report = $pruner->prune([]);

        $this->assertSame([], $report->stale);
        $this->assertSame(0, $report->keptCount);
    }

    /** @param array<int, array<int, int>> $occupants */
    private function pruner(GuestMachineIndex $index, EntityManagerInterface $entityManager, array $occupants = []): StaleGuestAccountPruner
    {
        $locator = $this->createStub(GuestMachineLocator::class);
        $locator->method('index')->willReturn($index);

        $items = $this->createStub(VmBatchItemRepository::class);
        $items->method('findDeployedBatchIdsByHostAndVmid')->willReturn($occupants);

        return new StaleGuestAccountPruner($locator, $this->createStub(GuestAccountRepository::class), $items, $entityManager);
    }

    private function account(int $vmid, ?int $batchId = null): GuestAccount
    {
        $host = new ProxmoxHost('pve', 'pve.example.test', 'moncampus');
        (new \ReflectionProperty(ProxmoxHost::class, 'id'))->setValue($host, 1);

        $account = new GuestAccount($host, 'pve1', $vmid, 'eleve');

        if (null !== $batchId) {
            // Stubbed rather than built: a VmBatch needs a program, a host and a range, and the only
            // thing this rule reads off it is its id.
            $batch = $this->createStub(VmBatch::class);
            $batch->method('getId')->willReturn($batchId);
            $account->setBatch($batch);
        }

        return $account;
    }

    private function guest(int $vmid): ProxmoxGuest
    {
        return new ProxmoxGuest($vmid, 'vm-'.$vmid, 'pve1', ProxmoxGuest::TYPE_QEMU, 'running', false, null, 2, 0.1, 0, 0, 0, null, null);
    }
}
