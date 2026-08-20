<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use App\Repository\IpAllocationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finishes a machine the creation wizard started: configuration, then the start if it was asked for.
 *
 * It exists because the wizard has no loop of its own. It answers a redirect the moment Proxmox
 * accepts the clone, and the clone lands a minute later with nobody left to act on it - so a machine
 * created that way was never configured at all: no hostname, no address, no SSH keys, no start.
 * configureAndStart() had exactly one caller, App\Service\VmBatch\VmBatchExecutor, and a batch is
 * the only thing that had a screen driving it step by step.
 *
 * What drives this one is whoever is watching the operation - the wizard's own last screen, or the
 * operations journal. **A machine nobody watches stays unconfigured**, which is the same bargain the
 * batch already makes ("the screen comes back until nothing moves") and is worth knowing rather than
 * hiding: closing the tab the second after creating leaves the machine cloned and untouched, and
 * reopening the operation finishes it.
 *
 * Every condition below is a way to do damage if got wrong, which is why they are named one by one
 * rather than folded into a single expression.
 */
class GuestCreationCompleter
{
    public function __construct(
        private readonly GuestCreator $creator,
        private readonly IpAllocationRepository $allocations,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Configures the machine if everything says it is time, and does nothing at all otherwise.
     *
     * Deliberately silent about the reasons: it runs inside a poller that answers a status, and a
     * refusal here is never news - "not yet" is the ordinary answer for most of a machine's first
     * minute.
     */
    public function completeIfReady(ProxmoxOperation $operation, ProxmoxHost $host): void
    {
        if (!$operation->isCompletionRequested() || null !== $operation->getConfiguredAt()) {
            return;
        }

        // Only a clone carries a cloud-init drive. A machine built from an ISO cannot be configured
        // at all - its address was reserved for somebody to type in by hand.
        if (ProxmoxAction::Clone !== $operation->getAction()) {
            return;
        }

        // Writing into a guest Proxmox is still copying is refused, so the task has to have landed.
        if (ProxmoxOperationStatus::Succeeded !== $operation->getStatus()) {
            return;
        }

        $allocation = $this->allocations->findOneByOperation($operation);
        $range = $allocation?->getRange();
        $node = $operation->getNode();
        $vmid = $operation->getVmid();

        if (null === $allocation || null === $range || null === $node || null === $vmid) {
            return;
        }

        // Marked before the call, not after: a configuration that throws must not be retried on
        // every poll for ever. The machine is left where it is and the failure is the operation's
        // own message; somebody looking at it can act.
        $operation->markConfigured();
        $this->entityManager->flush();

        try {
            $this->configure($host, $operation, $range, $allocation->getIp(), $node, $vmid);
        } catch (ProxmoxUnavailableException $exception) {
            // The clone did succeed, but "create a machine" means clone *and* configure, and a
            // machine left with the template's identity is not what was asked for. Recording it as
            // a failure with the hypervisor's own words is the only channel this has to say so.
            $operation->markFailed($exception->getMessage());
            $this->entityManager->flush();
        }
    }

    /** @throws ProxmoxUnavailableException */
    private function configure(ProxmoxHost $host, ProxmoxOperation $operation, IpRange $range, string $ip, string $node, int $vmid): void
    {
        $this->creator->configureAndStart($host, new GuestCreationRequest(
            hostname: $operation->getGuestName() ?? 'machine',
            vmid: $vmid,
            node: $node,
            cores: 0,
            memoryMib: 0,
            diskGib: 0,
            storage: '',
            range: $range,
            ip: $ip,
            // Non-null is what makes the request configurable, and the value itself is never used
            // by configureAndStart - the clone has already happened.
            sourceVmid: 0,
            linkedClone: false,
            isoVolumeId: null,
            startAfterCreation: $operation->wantsStartAfterCreation(),
        ));
    }
}
