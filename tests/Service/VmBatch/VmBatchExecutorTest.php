<?php

declare(strict_types=1);

namespace App\Tests\Service\VmBatch;

use App\Entity\Cohort;
use App\Entity\IpAllocation;
use App\Entity\IpRange;
use App\Entity\Program;
use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Entity\SchoolYear;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use App\Enum\VmBatchItemStatus;
use App\Repository\UserRepository;
use App\Repository\VmBatchItemRepository;
use App\Service\Guest\AccountPlan;
use App\Service\Guest\GuestAccountService;
use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\Guest\PostInstallRunner;
use App\Service\Guest\UnixLogin;
use App\Service\Network\IpAllocator;
use App\Service\Proxmox\GuestCreator;
use App\Service\Proxmox\ProxmoxClient;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxOperationTracker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The deployment chain, phase by phase: clone → configure → start → reachable → accounts.
 *
 * Tested with doubles rather than against a hypervisor, which is the whole reason GuestShell is a
 * one-method interface. Two properties matter more than the happy path and are the reason this file
 * exists:
 *
 * - **waiting is not failing.** A clone that Proxmox has not finished, and a machine that has been
 *   started but does not answer yet, are the normal state of a deployment for its first minute. If
 *   either were recorded as a failure, every batch would fail on its first pass.
 * - **the passwords never come back.** They are generated, sent to the machine and dropped; nothing
 *   this class returns carries one.
 */
class VmBatchExecutorTest extends TestCase
{
    private GuestCreator&Stub $creator;
    private IpAllocator&Stub $allocator;
    private GuestAccountService&Stub $accounts;
    private VmBatchItemRepository&Stub $items;
    private ProxmoxOperationTracker&Stub $tracker;
    private GuestShellFactory&Stub $shells;
    private PostInstallRunner&Stub $postInstall;
    private ?ProxmoxClientFactory $clientFactory = null;

    // Stubs by default and mocks only where a test actually asserts an interaction: phpunit.dist.xml
    // sets failOnNotice, and a mock nobody sets an expectation on is a notice.
    protected function setUp(): void
    {
        $this->creator = $this->createStub(GuestCreator::class);
        $this->allocator = $this->createStub(IpAllocator::class);
        $this->accounts = $this->createStub(GuestAccountService::class);
        $this->items = $this->createStub(VmBatchItemRepository::class);
        $this->tracker = $this->createStub(ProxmoxOperationTracker::class);
        $this->shells = $this->createStub(GuestShellFactory::class);
        $this->postInstall = $this->createStub(PostInstallRunner::class);
        $this->clientFactory = null;
    }

    public function testAPlannedMachineIsCloned(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Planned);
        $this->allocator = $this->createStub(IpAllocator::class);
        $this->allocator->method('reserveNext')->willReturn($this->createStub(IpAllocation::class));
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::once())->method('create')->willReturn($this->operation(ProxmoxOperationStatus::Running));
        $this->creator = $creator;

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Creating, $item->getStatus());
        self::assertSame(1, $result['progressed']);
    }

    public function testACloneStillRunningIsAWaitAndNotAFailure(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Creating);
        $item->setOperation($this->operation(ProxmoxOperationStatus::Running));
        $this->tracker->method('resolve')->willReturnArgument(0);
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('configureAndStart');
        $this->creator = $creator;

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Creating, $item->getStatus());
        self::assertSame(1, $result['waiting']);
        self::assertSame(0, $result['failed']);
    }

    public function testAFinishedCloneIsConfiguredAndStarted(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Creating);
        $item->setOperation($this->operation(ProxmoxOperationStatus::Succeeded));
        $item->setIpAllocation($this->allocation());
        $this->tracker->method('resolve')->willReturnArgument(0);
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::once())->method('configureAndStart');
        $this->creator = $creator;

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Created, $item->getStatus());
        self::assertSame(1, $result['progressed']);
    }

    /**
     * The defect this pins is not a crash but a demand for a privilege: the clone task belongs to
     * the provisioning account, and Proxmox answers `HTTP 403 (/nodes/<node>, Sys.Audit)` to an
     * account asking about someone else's task. Polling it with the everyday client is what reached
     * production, and it is invisible to every other test here because they all double the tracker.
     */
    public function testACloneTaskIsPolledWithTheAccountThatOpenedIt(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Creating);
        $item->setOperation($this->operation(ProxmoxOperationStatus::Succeeded));
        $item->setIpAllocation($this->allocation());
        $this->tracker->method('resolve')->willReturnArgument(0);

        $factory = $this->createMock(ProxmoxClientFactory::class);
        $factory->expects(self::once())
            ->method('forAction')
            ->with(self::anything(), ProxmoxAction::Clone)
            ->willReturn($this->createStub(ProxmoxClient::class));
        $factory->expects(self::never())->method('operate');
        $this->clientFactory = $factory;

        $this->deployOnce($batch, $item);
    }

    public function testACloneThatProxmoxReportedAsFailedIsNotRetriedOnItsOwn(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Creating);
        $item->setOperation($this->operation(ProxmoxOperationStatus::Failed));
        $this->tracker->method('resolve')->willReturnArgument(0);
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('create');
        $this->creator = $creator;

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Failed, $item->getStatus());
        self::assertSame(1, $result['failed']);
    }

    /**
     * A pass must answer inside PHP's `max_execution_time`, whatever the machines are doing.
     *
     * This is the shape that reached production: five machines cloned and started, none of them
     * answering SSH yet, and every pass spending its whole budget on the first of them before the
     * engine killed the request at thirty seconds. A fatal error is not caught, so the pass wrote
     * nothing - and because a pass always takes the *first* five resumable items, the sixth machine
     * onwards could never begin. The batch looked stuck at five for ever.
     *
     * The budget is checked before a step is begun rather than during it: the steps are already
     * bounded individually, and stopping between two of them is what makes the remainder a wait
     * instead of a loss.
     */
    public function testAPassStopsWhenItsBudgetIsSpentInsteadOfBeingKilled(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Planned);
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('create');
        $this->creator = $creator;

        // Nothing is attempted at all with no budget, which is the guard reduced to its extreme -
        // and the only value that makes the assertion independent of how fast the machine is.
        $result = $this->deployOnce($batch, $item, budgetSeconds: 0.0);

        self::assertSame(VmBatchItemStatus::Planned, $item->getStatus(), 'an item nobody got to must keep its place in the queue');
        self::assertSame(1, $result['waiting']);
        self::assertSame(0, $result['failed'], 'running out of budget is not a failure');
    }

    public function testAMachineThatDoesNotAnswerYetIsAWaitAndNotAFailure(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $this->shells->method('open')->willThrowException(new GuestUnreachableException('no route'));

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Created, $item->getStatus());
        self::assertSame(1, $result['waiting']);
        self::assertSame(0, $result['failed']);
    }

    /**
     * Every item a pass takes in hand loses its turn, whatever came of it.
     *
     * This is half of the fix for a batch that read as stuck at five: a pass takes the items that
     * have gone longest without a turn, and this stamp is what that order is built on. It is set
     * before the step rather than after, so an item that fails - and a failed item is deliberately
     * re-attempted here - still goes to the back of the queue instead of holding its slot for ever.
     * The other half is the ordering itself, in App\Tests\Functional\VmBatchTurnsTest.
     */
    public function testAnAttemptedItemLosesItsTurnEvenWhenItDoesNotProgress(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $this->shells->method('open')->willThrowException(new GuestUnreachableException('no route'));

        self::assertNull($item->getLastAttemptAt());

        $result = $this->deployOnce($batch, $item);

        self::assertSame(1, $result['waiting'], 'a machine that does not answer yet is still only waiting');
        self::assertNotNull($item->getLastAttemptAt(), 'a waiting item must not keep its turn');
    }

    public function testAMissingPlatformKeyFailsRatherThanWaitsForEver(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $this->shells->method('open')->willThrowException(new PlatformKeyUnavailableException('no key'));

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Failed, $item->getStatus());
        self::assertSame(1, $result['failed']);
    }

    public function testAReachableMachineGetsItsAccountsAndIsDone(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $this->shells->method('open')->willReturn($this->createStub(GuestShell::class));
        $accounts = $this->createMock(GuestAccountService::class);
        $accounts->method('refresh')->willReturn(new AccountPlan([], [], [], []));
        $this->accounts = $accounts;
        $accounts->expects(self::once())->method('apply')
            // The passwords must be the throwaway kind: nothing will ever display them.
            ->with(self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), false)
            ->willReturn(['operation' => $this->operation(ProxmoxOperationStatus::Succeeded), 'passwords' => ['celia-l' => 'secret']]);

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Provisioned, $item->getStatus());
        self::assertSame(1, $result['progressed']);
        self::assertSame(0, $result['remaining']);
    }

    public function testNothingTheExecutorReturnsCarriesAPassword(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $this->shells->method('open')->willReturn($this->createStub(GuestShell::class));
        $this->accounts->method('refresh')->willReturn(new AccountPlan([], [], [], []));
        $this->accounts->method('apply')->willReturn([
            'operation' => $this->operation(ProxmoxOperationStatus::Succeeded),
            'passwords' => ['celia-l' => 'Sup3rSecretThrowaway'],
        ]);

        $result = $this->deployOnce($batch, $item);

        self::assertStringNotContainsString('Sup3rSecretThrowaway', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    public function testALoginUseraddWouldRefuseStopsTheMachineInsteadOfBeingSkipped(): void
    {
        // The platform login is taken as it stands, and a directory can hold things Unix cannot.
        // GuestAccountSyncer would `continue` past this one, leaving a student with no account and
        // nothing anywhere saying so - which is precisely what must not happen.
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created, [
            ['userId' => 1, 'label' => 'Marie Dupont', 'login' => 'Marie.Dupont@school.fr'],
        ]);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Failed, $item->getStatus());
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('Marie.Dupont@school.fr', (string) $item->getMessage());
    }

    public function testThePostInstallScriptRunsOnlyWhenThereIsOne(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $this->shells->method('open')->willReturn($this->createStub(GuestShell::class));
        $this->accounts->method('refresh')->willReturn(new AccountPlan([], [], [], []));
        $this->accounts->method('apply')->willReturn(['operation' => $this->operation(ProxmoxOperationStatus::Succeeded), 'passwords' => []]);
        $postInstall = $this->createMock(PostInstallRunner::class);
        $postInstall->expects(self::never())->method('run');
        $this->postInstall = $postInstall;

        $this->deployOnce($batch, $item);
    }

    /** @return array{attempted: int, progressed: int, waiting: int, failed: int, remaining: int} */
    /**
     * The defect this pins is the one that made a whole class fail at once, and it is invisible in
     * every single-item test above: BATCH_SIZE limits how many machines a *pass* touches, not how
     * many clones are running. A pass that starts a clone reports progress, the screen presses
     * again immediately, and the next never-attempted item is the one with the longest wait - so
     * twenty-four machines became twenty-four simultaneous disk copies. None of them lands quickly
     * after that, the screen sees nothing move and gives up on the batch, and the machines are left
     * cloned and never configured: template address, no account.
     */
    public function testAPassStartsNoNewCloneWhileTheHypervisorIsAlreadyCopyingTwo(): void
    {
        $batch = $this->batch();
        $inFlight = [$this->item($batch, VmBatchItemStatus::Creating, 1), $this->item($batch, VmBatchItemStatus::Creating, 2)];
        $planned = $this->item($batch, VmBatchItemStatus::Planned, 3);

        foreach ($inFlight as $item) {
            $item->setOperation($this->operation(ProxmoxOperationStatus::Running));
        }

        $this->tracker->method('resolve')->willReturnArgument(0);
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('create');
        $this->creator = $creator;

        // Ordered as the repository orders them: never attempted first, which is exactly what used
        // to hand the pass to the planned machine.
        $result = $this->deployList($batch, [$planned, ...$inFlight]);

        self::assertSame(VmBatchItemStatus::Planned, $planned->getStatus());
        self::assertSame(1, $result['attempted']);
    }

    public function testAPassStartsACloneAgainAsSoonAsOneHasLanded(): void
    {
        $batch = $this->batch();
        $creating = $this->item($batch, VmBatchItemStatus::Creating, 1);
        $creating->setOperation($this->operation(ProxmoxOperationStatus::Running));
        $planned = $this->item($batch, VmBatchItemStatus::Planned, 2);

        $this->allocator = $this->createStub(IpAllocator::class);
        $this->allocator->method('reserveNext')->willReturn($this->createStub(IpAllocation::class));
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::once())->method('create')->willReturn($this->operation(ProxmoxOperationStatus::Running));
        $this->creator = $creator;

        $result = $this->deployList($batch, [$planned, $creating]);

        self::assertSame(VmBatchItemStatus::Creating, $planned->getStatus());
        self::assertSame(1, $result['progressed']);
    }

    public function testWhatIsLeftDistinguishesTheSlowFromTheRefused(): void
    {
        // The screen loops while machines are merely slow and stops once everything outstanding has
        // refused. Reporting only "remaining" made one refusal end the pass for every other machine.
        $batch = $this->batch();
        $refused = $this->item($batch, VmBatchItemStatus::Failed, 1);
        $slow = $this->item($batch, VmBatchItemStatus::Created, 2);
        $slow->setIpAllocation($this->allocation());

        $shells = $this->createStub(GuestShellFactory::class);
        $shells->method('open')->willThrowException(new GuestUnreachableException('No route to host'));
        $this->shells = $shells;

        $result = $this->deployList($batch, [$slow, $refused]);

        self::assertSame(2, $result['remaining']);
        self::assertSame(1, $result['blocked']);
    }

    private function deployOnce(VmBatch $batch, VmBatchItem $item, float $budgetSeconds = 60.0): array
    {
        // First call returns the outstanding item, the second (after the pass) recounts what is left.
        $this->items->method('findResumable')->willReturnCallback(
            static fn (): array => $item->getStatus()->isResumable() ? [$item] : [],
        );

        $executor = new \App\Service\VmBatch\VmBatchExecutor(
            $this->creator,
            $this->allocator,
            $this->accounts,
            $this->items,
            $this->createStub(UserRepository::class),
            $this->tracker,
            $this->clientFactory(),
            $this->shells,
            $this->postInstall,
            new UnixLogin(),
            $this->createStub(EntityManagerInterface::class),
            $budgetSeconds,
        );

        return $executor->run($batch, null);
    }

    private function clientFactory(): ProxmoxClientFactory
    {
        if (null !== $this->clientFactory) {
            return $this->clientFactory;
        }

        $factory = $this->createStub(ProxmoxClientFactory::class);
        $factory->method('forAction')->willReturn($this->createStub(ProxmoxClient::class));

        return $factory;
    }

    /**
     * @return array{VmBatch, VmBatchItem}
     */
    /**
     * A pass over a batch of several machines, in the order the repository would hand them over.
     *
     * @param list<VmBatchItem> $items
     *
     * @return array{attempted: int, progressed: int, waiting: int, failed: int, remaining: int, blocked: int}
     */
    private function deployList(VmBatch $batch, array $items): array
    {
        $this->items->method('findResumable')->willReturnCallback(
            static fn (): array => array_values(array_filter(
                $items,
                static fn (VmBatchItem $item): bool => $item->getStatus()->isResumable(),
            )),
        );

        $executor = new \App\Service\VmBatch\VmBatchExecutor(
            $this->creator,
            $this->allocator,
            $this->accounts,
            $this->items,
            $this->createStub(UserRepository::class),
            $this->tracker,
            $this->clientFactory(),
            $this->shells,
            $this->postInstall,
            new UnixLogin(),
            $this->createStub(EntityManagerInterface::class),
            60.0,
        );

        return $executor->run($batch, null);
    }

    private function batch(): VmBatch
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));

        return new VmBatch('TP', $program, $this->createStub(ProxmoxHost::class), $this->createStub(IpRange::class), 9000, 'pve');
    }

    private function item(VmBatch $batch, VmBatchItemStatus $status, int $position): VmBatchItem
    {
        $item = new VmBatchItem($batch, \sprintf('Groupe %d', $position), \sprintf('tp-%02d', $position), \sprintf('groupe-%d', $position), $position);
        $item->setStatus($status);
        $item->setVmid(9000 + $position);
        $batch->addItem($item);

        return $item;
    }

    private function batchWithItem(VmBatchItemStatus $status, array $groupMembers = []): array
    {
        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $batch = new VmBatch('TP', $program, $this->createStub(ProxmoxHost::class), $this->createStub(IpRange::class), 9000, 'pve');
        $item = new VmBatchItem($batch, 'Groupe 1', 'tp-01', 'groupe-1', 1, $groupMembers);
        $item->setStatus($status);
        $batch->addItem($item);

        return [$batch, $item];
    }

    private function allocation(): IpAllocation
    {
        $allocation = $this->createStub(IpAllocation::class);
        $allocation->method('getIp')->willReturn('10.30.20.51');

        return $allocation;
    }

    private function operation(ProxmoxOperationStatus $status, ProxmoxAction $action = ProxmoxAction::Clone): ProxmoxOperation&Stub
    {
        $operation = $this->createStub(ProxmoxOperation::class);
        $operation->method('getStatus')->willReturn($status);
        // Clone by default: every operation a batch opens is a creation, and the action is what
        // settle() reads to know which account owns the task it is about to poll.
        $operation->method('getAction')->willReturn($action);

        return $operation;
    }
}
