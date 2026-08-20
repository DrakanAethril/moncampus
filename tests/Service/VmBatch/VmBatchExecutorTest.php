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
use App\Enum\ProxmoxOperationStatus;
use App\Enum\VmBatchItemStatus;
use App\Repository\UserRepository;
use App\Repository\VmBatchItemRepository;
use App\Service\Guest\AccountPlan;
use App\Service\Guest\GuestAccountService;
use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyProvider;
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
    private function deployOnce(VmBatch $batch, VmBatchItem $item): array
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
            $this->createStub(PlatformKeyProvider::class),
            $this->postInstall,
            new UnixLogin(),
            $this->createStub(EntityManagerInterface::class),
        );

        return $executor->run($batch, null);
    }

    private function clientFactory(): ProxmoxClientFactory&Stub
    {
        $factory = $this->createStub(ProxmoxClientFactory::class);
        $factory->method('operate')->willReturn($this->createStub(ProxmoxClient::class));

        return $factory;
    }

    /**
     * @param list<array{userId: int, label: string, login: string}> $groupMembers
     *
     * @return array{VmBatch, VmBatchItem}
     */
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

    private function operation(ProxmoxOperationStatus $status): ProxmoxOperation&Stub
    {
        $operation = $this->createStub(ProxmoxOperation::class);
        $operation->method('getStatus')->willReturn($status);

        return $operation;
    }
}
