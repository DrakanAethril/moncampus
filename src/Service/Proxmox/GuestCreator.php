<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\IpAllocation;
use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Entity\User;
use App\Enum\ProxmoxAction;
use App\Service\Guest\GuestAuthorizedKeys;
use App\Service\Network\GuestNetworkConfigurator;
use App\Service\Network\IpAllocator;
use Symfony\Component\Lock\LockFactory;

/**
 * Creates one machine: clone or blank, then identity and address, then optionally start it.
 *
 * **The order is not negotiable**, and it is the reason this is a service rather than three calls
 * in a controller:
 *
 *     clone → configure (name, address) → start
 *
 * Configuring after starting is configuring nothing: cloud-init applies its configuration at the
 * *first* boot and never again. A machine started before it was named comes up with the template's
 * hostname and the template's addressing, and no amount of later PUTs changes that - it would take
 * a second reboot nobody asked for.
 *
 * Two failure rules, both of which exist because getting them wrong is silent:
 *
 *  - **the address is released the moment creation fails.** Without it a range empties itself one
 *    failed attempt at a time, and nobody notices until it holds nothing but addresses no machine
 *    carries.
 *  - **the operation row is opened before the first call**, so a creation that vanishes into a dead
 *    network still records who asked for it.
 *
 * An ISO installation cannot be configured at all - there is no cloud-init drive to write into - so
 * the address is reserved and the values are handed to a human to type. That path is not marginal:
 * with no Windows template in the fleet it is half the machines.
 */
class GuestCreator
{
    public function __construct(
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxScopeGuard $scopeGuard,
        private readonly ProxmoxInventory $inventory,
        private readonly ProxmoxOperationTracker $tracker,
        private readonly IpAllocator $allocator,
        private readonly GuestNetworkConfigurator $configurator,
        private readonly GuestAuthorizedKeys $authorizedKeys,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @throws ProxmoxUnavailableException on a refusal of the perimeter or a quota, on a VMID
     *                                     already in use, or on anything the hypervisor refuses
     */
    public function create(ProxmoxHost $host, GuestCreationRequest $request, IpAllocation $allocation, ?User $requestedBy): ProxmoxOperation
    {
        $scope = ProxmoxScope::fromHost($host);
        $refusal = $this->scopeGuard->refusal($scope, ProxmoxAction::Create, $request->vmid, $host->getManagedPool());

        if (null !== $refusal) {
            throw new ProxmoxUnavailableException($refusal);
        }

        $quota = $this->scopeGuard->quotaRefusal(
            $scope,
            $request->cores,
            $request->memoryMib,
            $request->diskGib,
            $this->countExistingGuests($host, $scope),
        );

        if (null !== $quota) {
            throw new ProxmoxUnavailableException($quota);
        }

        // Keyed on the VMID rather than on the host: two administrators creating different machines
        // at once is fine and common, two of them landing on the same VMID is what must not happen.
        $lock = $this->lockFactory->createLock(\sprintf('proxmox-create-%d-%d', $host->getId() ?? 0, $request->vmid), ttl: 300.0);

        if (!$lock->acquire()) {
            throw new ProxmoxUnavailableException('proxmoxRefusalBusy');
        }

        $operation = $this->tracker->begin(
            $host,
            null !== $request->sourceVmid ? ProxmoxAction::Clone : ProxmoxAction::Create,
            $requestedBy,
            $request->node,
            $request->vmid,
            $request->hostname,
            ProxmoxGuest::TYPE_QEMU,
        );

        try {
            $client = $this->clientFactory->provision($host);

            $upid = null !== $request->sourceVmid
                ? $this->clone($client, $host, $request)
                : $this->createBlank($client, $host, $request);

            $this->tracker->accepted($operation, $upid);
            $this->allocator->assign($allocation, $request->vmid, $request->node, $operation);

            return $operation;
        } catch (\Throwable $exception) {
            // Released here and now, not by a later sweep: the range must not lose an address to
            // every failed attempt.
            $this->allocator->release($allocation);
            $this->tracker->failed($operation, $exception->getMessage());

            throw $exception instanceof ProxmoxUnavailableException ? $exception : new ProxmoxUnavailableException($exception->getMessage(), previous: $exception);
        } finally {
            $lock->release();
        }
    }

    /**
     * The second half, run once the clone task has finished: a machine only accepts its
     * configuration when Proxmox has stopped working on it.
     *
     * Separate from create() because the two are separated by a wait - the clone is a task with a
     * UPID, and writing into a guest that is still being copied is refused.
     *
     * The keys are asked for here rather than handed in, so that no caller can create a machine
     * without them - see App\Service\Guest\GuestAuthorizedKeys for what the set is and why it is
     * read now rather than kept.
     *
     * @return list<string> who each installed key belongs to, for the machine's installation log -
     *                      empty for a machine that has no cloud-init drive to write into
     */
    public function configureAndStart(ProxmoxHost $host, GuestCreationRequest $request): array
    {
        if (!$request->isConfigurable()) {
            return [];
        }

        $client = $this->clientFactory->provision($host);
        $range = $request->range;
        $path = \sprintf('/nodes/%s/qemu/%d/config', rawurlencode($request->node), $request->vmid);

        // Read before write, so the card can be merged rather than replaced: the clone inherits the
        // template's `net0`, which may carry a firewall flag, an MTU or a rate limit that this
        // application knows nothing about and would otherwise drop without a word. Costs one call,
        // on the path that is already several.
        $existingNet0 = $client->get($path)->nullableString('net0');
        $keys = $this->authorizedKeys->forNewGuest();

        $parameters = $this->configurator->qemuParameters(
            $request->hostname,
            $request->ip,
            $range->getPrefixLength(),
            $range->getGateway(),
            $range->getBridge(),
            $range->getVlan(),
            $keys->material,
            // The account is created here rather than borrowed from the image: naming it in
            // cloud-init is what makes it exist, and it is the same one this application then logs
            // in with. See ProxmoxHost::$guestLoginUser.
            cloudInitUser: $host->getGuestLoginUser(),
            existingNet0: $existingNet0,
        );

        $client->put($path, $parameters);

        // Only now: cloud-init writes its configuration at the first boot and never again, so a
        // machine started before this PUT comes up as a copy of the template, for good.
        if ($request->startAfterCreation) {
            $client->post(\sprintf('/nodes/%s/qemu/%d/status/start', rawurlencode($request->node), $request->vmid));
        }

        return $keys->descriptors;
    }

    /**
     * How many machines the perimeter already holds, read from the hypervisor rather than from the
     * list the wizard displayed: a ceiling has to be weighed against what is true at the moment of
     * creation, and the screen the administrator is looking at may be several minutes old.
     *
     * Costs one call, and only when a ceiling is actually declared - a host that names no "machines
     * maximum" pays nothing for a limit it does not have. The reading goes through the provisioning
     * account rather than the operating one: it is the account that is about to create, so a
     * perimeter it cannot see is a creation it could not perform either.
     *
     * @throws ProxmoxUnavailableException when the host cannot be reached, which is deliberate -
     *                                     creating blind under an unverified ceiling would defeat
     *                                     the point of declaring one
     */
    private function countExistingGuests(ProxmoxHost $host, ProxmoxScope $scope): int
    {
        if (null === $scope->maxGuests) {
            return 0;
        }

        $guests = $this->inventory->guests($this->clientFactory->provision($host));

        return $this->scopeGuard->countCovered($scope, $this->inventory->machines($guests));
    }

    private function clone(ProxmoxClient $client, ProxmoxHost $host, GuestCreationRequest $request): ?string
    {
        $body = [
            'newid' => $request->vmid,
            'name' => $request->hostname,
            // full=0 is the linked clone: near-instant, and it only occupies the delta. The cost is
            // that it stays on the template's node and storage - which is why the screen states the
            // consequence rather than the vocabulary.
            'full' => $request->linkedClone ? 0 : 1,
            'target' => $request->node,
        ];

        if (!$request->linkedClone) {
            $body['storage'] = $request->storage;
        }

        $pool = $host->getManagedPool();
        if (null !== $pool && '' !== $pool) {
            $body['pool'] = $pool;
        }

        return $client->post(
            \sprintf('/nodes/%s/qemu/%d/clone', rawurlencode($request->node), $request->sourceVmid ?? 0),
            $body,
        )->scalar();
    }

    private function createBlank(ProxmoxClient $client, ProxmoxHost $host, GuestCreationRequest $request): ?string
    {
        $range = $request->range;

        $body = [
            'vmid' => $request->vmid,
            'name' => $request->hostname,
            'cores' => $request->cores,
            'memory' => $request->memoryMib,
            'scsihw' => 'virtio-scsi-single',
            'scsi0' => \sprintf('%s:%d', $request->storage, $request->diskGib),
            'net0' => \sprintf(
                'virtio,bridge=%s%s',
                $range->getBridge(),
                null !== $range->getVlan() ? ',tag='.$range->getVlan() : '',
            ),
            'ostype' => 'l26',
        ];

        if (null !== $request->isoVolumeId && '' !== $request->isoVolumeId) {
            $body['ide2'] = $request->isoVolumeId.',media=cdrom';
            // Boot from the disc first, since that is the entire point of this path.
            $body['boot'] = 'order=ide2;scsi0';
        }

        $pool = $host->getManagedPool();
        if (null !== $pool && '' !== $pool) {
            $body['pool'] = $pool;
        }

        return $client->post(\sprintf('/nodes/%s/qemu', rawurlencode($request->node)), $body)->scalar();
    }
}
