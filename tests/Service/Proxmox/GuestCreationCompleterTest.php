<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxmox;

use App\Entity\IpAllocation;
use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use App\Repository\IpAllocationRepository;
use App\Service\Proxmox\GuestCreationCompleter;
use App\Service\Proxmox\GuestCreator;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Finishing a machine the creation wizard started.
 *
 * The wizard answers a redirect the moment Proxmox accepts the clone, so nothing of it is still
 * running when the clone lands a minute later - which is why, until now, a machine created that way
 * was never configured at all: no hostname, no address, no keys, no start. The batch had its own
 * loop and was the only caller of configureAndStart().
 *
 * What is pinned here is the set of conditions, because each of them is a way to do damage: to
 * configure a machine twice, to configure one the batch is already driving, or to write into a
 * clone Proxmox has not finished copying.
 */
class GuestCreationCompleterTest extends TestCase
{
    public function testAFinishedCloneIsConfigured(): void
    {
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::once())->method('configureAndStart');

        $operation = $this->operation();
        $this->completer($creator)->completeIfReady($operation, $this->host());

        self::assertNotNull($operation->getConfiguredAt(), 'a configured machine must not be configured again');
    }

    public function testAMachineNobodyAskedToBeFinishedIsLeftAlone(): void
    {
        $creator = $this->createMock(GuestCreator::class);
        // A batch machine: VmBatchExecutor drives it, and configuring it here would do it twice.
        $creator->expects(self::never())->method('configureAndStart');

        $operation = $this->operation(completionRequested: false);
        $this->completer($creator)->completeIfReady($operation, $this->host());

        self::assertNull($operation->getConfiguredAt());
    }

    /** Writing into a guest Proxmox is still copying is refused - the clone has to have landed. */
    public function testACloneStillRunningIsNotConfiguredYet(): void
    {
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('configureAndStart');

        $this->completer($creator)->completeIfReady($this->operation(succeeded: false), $this->host());
    }

    public function testAMachineAlreadyConfiguredIsNotConfiguredTwice(): void
    {
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('configureAndStart');

        $operation = $this->operation();
        $operation->markConfigured();

        $this->completer($creator)->completeIfReady($operation, $this->host());
    }

    /**
     * An ISO installation has no cloud-init drive to write into, so there is nothing to configure -
     * the address was reserved for a human to type in. Asking anyway would fail against Proxmox.
     */
    public function testAnIsoInstallationHasNothingToConfigure(): void
    {
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('configureAndStart');

        $this->completer($creator)->completeIfReady($this->operation(action: ProxmoxAction::Create), $this->host());
    }

    /** No address means no request can be rebuilt: the machine is left as it is rather than half-done. */
    public function testAMachineWithNoAddressIsLeftAlone(): void
    {
        $creator = $this->createMock(GuestCreator::class);
        $creator->expects(self::never())->method('configureAndStart');

        $this->completer($creator, withAddress: false)->completeIfReady($this->operation(), $this->host());
    }

    /**
     * A configuration that the hypervisor refuses must not take the poller down with it: the route
     * answers a status and has to go on answering one. The clone did succeed, but a machine left
     * with the template's identity is not what was asked for, so the operation says so.
     */
    public function testAConfigurationTheHypervisorRefusesIsRecordedRatherThanRaised(): void
    {
        $creator = $this->createStub(GuestCreator::class);
        $creator->method('configureAndStart')->willThrowException(new ProxmoxUnavailableException('403 Permission check failed'));

        $operation = $this->operation();
        $this->completer($creator)->completeIfReady($operation, $this->host());

        self::assertSame(ProxmoxOperationStatus::Failed, $operation->getStatus());
        self::assertSame('403 Permission check failed', $operation->getMessage());
    }

    private function completer(GuestCreator $creator, bool $withAddress = true): GuestCreationCompleter
    {
        $allocations = $this->createStub(IpAllocationRepository::class);
        $allocations->method('findOneByOperation')->willReturn($withAddress ? $this->allocation() : null);

        return new GuestCreationCompleter($creator, $allocations, $this->createStub(EntityManagerInterface::class));
    }

    private function allocation(): IpAllocation
    {
        $range = new IpRange('salle', $this->host(), '10.30.0.0/24', '10.30.0.254', '10.30.0.1', '10.30.0.253');
        $range->setBridge('vmbr1')->setVlan(40);

        return new IpAllocation($range, '10.30.0.10');
    }

    private function host(): ProxmoxHost
    {
        $host = new ProxmoxHost('campus', '192.0.2.10', 'svc');
        $host->setPort(8006)->setSecretCipher('sealed');

        return $host;
    }

    private function operation(bool $completionRequested = true, bool $succeeded = true, ProxmoxAction $action = ProxmoxAction::Clone): ProxmoxOperation
    {
        $operation = new ProxmoxOperation($this->host(), $action, null);
        $operation->describeGuest('pve', 250, 'poste-01', 'qemu');

        if ($completionRequested) {
            $operation->requestCompletion(false);
        }

        if ($succeeded) {
            $operation->markSucceeded();
        }

        return $operation;
    }
}
