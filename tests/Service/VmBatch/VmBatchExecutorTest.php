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
use App\Enum\VmInstallStep;
use App\Repository\UserRepository;
use App\Repository\VmBatchItemRepository;
use App\Service\Console\GuestPty;
use App\Service\Guest\AccountPlan;
use App\Service\Guest\GuestAccountService;
use App\Service\Guest\GuestCommandFailedException;
use App\Service\Guest\GuestCommandResult;
use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestTimeSync;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\Guest\PostInstallRunner;
use App\Service\Guest\UnixLogin;
use App\Service\Network\IpAllocator;
use App\Service\Proxmox\GuestCreator;
use App\Service\Proxmox\GuestPowerRunner;
use App\Service\Proxmox\ProxmoxClient;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxOperationTracker;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The deployment chain, phase by phase: clone → configure → start → reachable → accounts →
 * shutdown.
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
    private ProxmoxInventory&Stub $inventory;
    private GuestPowerRunner&Stub $power;

    /** Left unset by default: the real one only builds a command, and no shell here executes it. */
    private ?GuestTimeSync $timeSync = null;
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
        // Every machine these tests build, seen as running - so the last step of a pass, switching
        // it off, takes its ordinary path rather than the "the host has no such machine" branch.
        $this->inventory = $this->createStub(ProxmoxInventory::class);
        $this->inventory->method('guests')->willReturn(array_map(
            static fn (int $vmid): ProxmoxGuest => new ProxmoxGuest($vmid, \sprintf('tp-%d', $vmid), 'pve', ProxmoxGuest::TYPE_QEMU, 'running', false, null, 2, 0.1, 2048, 512, 20, 60, null),
            [210, ...range(9000, 9030)],
        ));
        $this->power = $this->createStub(GuestPowerRunner::class);
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
        $this->shells->method('open')->willReturn($this->guestShell());
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

    public function testTheClockIsPointedAtTheGatewayOfItsOwnRange(): void
    {
        [$batch, $item] = $this->batchOnGateway('10.30.20.254');

        $timeSync = $this->createMock(GuestTimeSync::class);
        $timeSync->expects(self::once())->method('configure')->with(self::anything(), '10.30.20.254');
        $this->timeSync = $timeSync;

        $this->deployOnce($batch, $item);

        $steps = array_map(static fn (array $entry): VmInstallStep => $entry['step'], $item->getInstallLogEntries());

        self::assertContains(VmInstallStep::TimeSyncConfigured, $steps);
        self::assertSame(VmBatchItemStatus::Provisioned, $item->getStatus());
    }

    /**
     * Red in the log, and the machine still finishes. A clock nothing sets is a real problem and
     * says so, but it is not one that should hold a whole class behind a template missing a
     * package - one machine at a time being the rule.
     */
    public function testAClockThatCannotBeSetIsRecordedWithoutFailingTheMachine(): void
    {
        [$batch, $item] = $this->batchOnGateway('10.30.20.254');

        $timeSync = $this->createStub(GuestTimeSync::class);
        $timeSync->method('configure')->willThrowException(
            new GuestCommandFailedException('chrony is not installed on this machine: no chrony.conf found.'),
        );
        $this->timeSync = $timeSync;

        $result = $this->deployOnce($batch, $item);

        $failed = array_values(array_filter(
            $item->getInstallLogEntries(),
            static fn (array $entry): bool => VmInstallStep::TimeSyncFailed === $entry['step'],
        ));

        self::assertCount(1, $failed);
        self::assertFalse($failed[0]['ok']);
        self::assertStringContainsString('chrony', (string) $failed[0]['detail']);
        self::assertSame(VmBatchItemStatus::Provisioned, $item->getStatus());
        self::assertSame(1, $result['progressed']);
    }

    /**
     * The last link of the chain, and the one somebody will call a bug: a machine that has just
     * been provisioned is switched off, not left running. It was started to be configured, and the
     * person it was built for starts it again when they need it.
     */
    public function testAProvisionedMachineIsSwitchedOff(): void
    {
        [$batch, $item] = $this->readyMachine();

        $power = $this->createMock(GuestPowerRunner::class);
        $power->expects(self::once())->method('run')
            // Shutdown and never stop: the machine is asked to go down, not cut off.
            ->with(self::anything(), self::anything(), ProxmoxAction::Shutdown, self::anything())
            ->willReturn($this->operation(ProxmoxOperationStatus::Succeeded, ProxmoxAction::Shutdown));
        $this->power = $power;

        $this->deployOnce($batch, $item);

        $steps = array_map(static fn (array $entry): VmInstallStep => $entry['step'], $item->getInstallLogEntries());

        self::assertContains(VmInstallStep::ShutdownRequested, $steps);
        self::assertSame(VmBatchItemStatus::Provisioned, $item->getStatus());
    }

    /**
     * Red in the log, and the machine still finishes - the same bargain as the clock. Everything
     * that was asked for is on it, and being left running is not a defect worth sending the next
     * pass back through the accounts and the post-installation script.
     */
    public function testAShutdownThatIsRefusedDoesNotFailTheMachine(): void
    {
        [$batch, $item] = $this->readyMachine();

        $power = $this->createStub(GuestPowerRunner::class);
        $power->method('run')->willThrowException(new ProxmoxUnavailableException('proxmoxRefusalActionNotAllowed'));
        $this->power = $power;

        $result = $this->deployOnce($batch, $item);

        $failed = array_values(array_filter(
            $item->getInstallLogEntries(),
            static fn (array $entry): bool => VmInstallStep::ShutdownFailed === $entry['step'],
        ));

        self::assertCount(1, $failed);
        self::assertFalse($failed[0]['ok']);
        self::assertSame(VmBatchItemStatus::Provisioned, $item->getStatus());
        self::assertSame(1, $result['progressed']);
    }

    /**
     * A machine that is up, answering, and whose accounts are already in line - the state a pass
     * finds it in when the only thing left to do is switch it off.
     *
     * Its range names no gateway, so the clock step stands aside: what these tests are about is the
     * step after it.
     *
     * @return array{VmBatch, VmBatchItem}
     */
    private function readyMachine(): array
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);

        $this->shells->method('open')->willReturn($this->guestShell());
        $accounts = $this->createStub(GuestAccountService::class);
        $accounts->method('refresh')->willReturn(new AccountPlan([], [], [], []));
        $accounts->method('apply')->willReturn(['operation' => $this->operation(ProxmoxOperationStatus::Succeeded), 'passwords' => []]);
        $this->accounts = $accounts;

        return [$batch, $item];
    }

    /**
     * A machine that is up, answering, and whose accounts are already in line - so the only thing
     * a pass has left to do is the clock.
     *
     * @return array{VmBatch, VmBatchItem}
     */
    private function batchOnGateway(string $gateway): array
    {
        $range = $this->createStub(IpRange::class);
        $range->method('getGateway')->willReturn($gateway);

        $program = new Program('SIO-2 2026-2027', 'SIO-2', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class));
        $batch = new VmBatch('TP', $program, $this->createStub(ProxmoxHost::class), $range, 9000, 'pve');
        $item = new VmBatchItem($batch, 'Groupe 1', 'tp-01', 'groupe-1', 1);
        $item->setStatus(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $batch->addItem($item);

        $this->shells->method('open')->willReturn($this->guestShell());
        $accounts = $this->createStub(GuestAccountService::class);
        $accounts->method('refresh')->willReturn(new AccountPlan([], [], [], []));
        $accounts->method('apply')->willReturn(['operation' => $this->operation(ProxmoxOperationStatus::Succeeded), 'passwords' => []]);
        $this->accounts = $accounts;

        return [$batch, $item];
    }

    public function testNothingTheExecutorReturnsCarriesAPassword(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(210);
        $this->shells->method('open')->willReturn($this->guestShell());
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
        $this->shells->method('open')->willReturn($this->guestShell());
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
    public function testAPassStartsNoNewMachineWhileOneIsStillBeingCloned(): void
    {
        $batch = $this->batch();
        $creating = $this->item($batch, VmBatchItemStatus::Creating, 1);
        $creating->setOperation($this->operation(ProxmoxOperationStatus::Running));
        $planned = $this->item($batch, VmBatchItemStatus::Planned, 2);

        $this->tracker->method('resolve')->willReturnArgument(0);
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('create');
        $this->creator = $creator;

        // Ordered as the repository orders them: never attempted first, which is exactly what used
        // to hand the pass to the planned machine.
        $result = $this->deployList($batch, [$planned, $creating]);

        self::assertSame(VmBatchItemStatus::Planned, $planned->getStatus());
        self::assertSame(1, $result['attempted']);
    }

    /**
     * The defect the deployments of August showed, and the one every earlier test missed: a machine
     * that has been cloned, configured and started is *not* finished - it is a minute away from
     * answering on SSH, and everything that matters (its accounts, the post-installation script)
     * happens after that. Counting only the clones as "in flight" meant the next machine was
     * launched the moment the previous one left that phase, so three or four were always being
     * built at once - which is exactly what "une par une" was asked to prevent.
     */
    public function testAPassStartsNoNewMachineWhileOneIsBootingEither(): void
    {
        $batch = $this->batch();
        $booting = $this->item($batch, VmBatchItemStatus::Created, 1);
        $booting->setIpAllocation($this->allocation());
        $planned = $this->item($batch, VmBatchItemStatus::Planned, 2);

        $shells = $this->createStub(GuestShellFactory::class);
        $shells->method('open')->willThrowException(new GuestUnreachableException('Connection refused'));
        $this->shells = $shells;

        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('create');
        $this->creator = $creator;

        $result = $this->deployList($batch, [$planned, $booting]);

        self::assertSame(VmBatchItemStatus::Planned, $planned->getStatus());
        self::assertSame(1, $result['attempted']);
    }

    public function testAPassStartsAMachineAgainOnlyOnceTheOneAheadIsInstalled(): void
    {
        $batch = $this->batch();
        // Provisioned is not resumable, so the queue holds nothing but the planned machine.
        $this->item($batch, VmBatchItemStatus::Provisioned, 1);
        $planned = $this->item($batch, VmBatchItemStatus::Planned, 2);

        $this->allocator = $this->createStub(IpAllocator::class);
        $this->allocator->method('reserveNext')->willReturn($this->createStub(IpAllocation::class));
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::once())->method('create')->willReturn($this->operation(ProxmoxOperationStatus::Running));
        $this->creator = $creator;

        $result = $this->deployList($batch, [$planned]);

        self::assertSame(VmBatchItemStatus::Creating, $planned->getStatus());
        self::assertSame(1, $result['progressed']);
    }

    /**
     * The other half of the rule. A refused machine is not going to finish on its own, and holding
     * the twenty-three that were doing fine behind it is the very thing a non-atomic batch exists
     * to avoid - so it does not count as one being built.
     */
    public function testARefusedMachineDoesNotHoldTheQueueBehindIt(): void
    {
        $batch = $this->batch();
        // Failed with no operation at all: its clone never left, so its phase is Planned again.
        $refused = $this->item($batch, VmBatchItemStatus::Failed, 1);
        $planned = $this->item($batch, VmBatchItemStatus::Planned, 2);

        $this->allocator = $this->createStub(IpAllocator::class);
        $this->allocator->method('reserveNext')->willReturn($this->createStub(IpAllocation::class));
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::once())->method('create')->willReturn($this->operation(ProxmoxOperationStatus::Running));
        $this->creator = $creator;

        $this->deployList($batch, [$planned, $refused]);

        self::assertSame(VmBatchItemStatus::Creating, $planned->getStatus());
    }

    /**
     * A boot takes a minute; an afternoon is not a boot. Left as a wait it held the whole class
     * behind one machine, one at a time being the rule.
     */
    public function testAMachineThatNeverAnswersIsFailedRatherThanWaitedOnForEver(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Created);
        $item->setIpAllocation($this->allocation());
        $item->setVmid(9001);

        // Entered the phase well before the ceiling: the property is stamped by setStatus(), so
        // rewinding it is how a test reaches an afternoon without waiting for one.
        $reflection = new \ReflectionProperty(VmBatchItem::class, 'phaseSince');
        $reflection->setValue($item, new \DateTimeImmutable('-2 hours'));

        $shells = $this->createStub(GuestShellFactory::class);
        $shells->method('open')->willThrowException(new GuestUnreachableException('No route to host'));
        $this->shells = $shells;

        $result = $this->deployOnce($batch, $item);

        self::assertSame(VmBatchItemStatus::Failed, $item->getStatus());
        self::assertSame(1, $result['failed']);
    }

    public function testAWaitWritesItsReasonIntoTheInstallationLogOnce(): void
    {
        [$batch, $item] = $this->batchWithItem(VmBatchItemStatus::Creating);
        $item->setOperation($this->operation(ProxmoxOperationStatus::Running));

        // The stall the batches of August showed: the poll itself refused, pass after pass, and the
        // log stopped at « clonage demandé » saying nothing about why.
        $factory = $this->createStub(ProxmoxClientFactory::class);
        $factory->method('forAction')->willThrowException(new ProxmoxUnavailableException('The provisioning account is refused.'));
        $this->clientFactory = $factory;

        $this->deployOnce($batch, $item);
        $this->deployOnce($batch, $item);

        $waits = array_values(array_filter(
            $item->getInstallLogEntries(),
            static fn (array $entry): bool => VmInstallStep::Waiting === $entry['step'],
        ));

        self::assertCount(1, $waits, 'the same reason on every pass must be written once');
        self::assertSame('The provisioning account is refused.', $waits[0]['detail']);
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
            $this->inventory,
            $this->power,
            $this->postInstall,
            $this->timeSync ?? new GuestTimeSync(),
            new UnixLogin(),
            new GuestPty(),
            $this->createStub(EntityManagerInterface::class),
            $budgetSeconds,
        );

        return $executor->run($batch, null);
    }

    /**
     * A machine that answers « tmux est là ».
     *
     * A bare stub cannot: GuestCommandResult is final, so PHPUnit has nothing to hand back from
     * runAsSelf() - and the console-preparation step of a pass calls it on every machine.
     */
    private function guestShell(): GuestShell
    {
        $shell = $this->createStub(GuestShell::class);
        $shell->method('runAsSelf')->willReturn(new GuestCommandResult('ready', 0));
        $shell->method('run')->willReturn(new GuestCommandResult('', 0));

        return $shell;
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
            $this->inventory,
            $this->power,
            $this->postInstall,
            $this->timeSync ?? new GuestTimeSync(),
            new UnixLogin(),
            new GuestPty(),
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
