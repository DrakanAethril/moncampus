<?php

declare(strict_types=1);

namespace App\Service\VmBatch;

use App\Entity\User;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\GuestAccountOrigin;
use App\Enum\VmBatchItemStatus;
use App\Repository\UserRepository;
use App\Repository\VmBatchItemRepository;
use App\Service\Guest\GuestAccountService;
use App\Service\Network\AddressUnavailableException;
use App\Service\Network\IpAllocator;
use App\Service\Network\RangeExhaustedException;
use App\Service\Proxmox\GuestCreationRequest;
use App\Service\Proxmox\GuestCreator;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deploys the machines of a batch, one at a time, and records each outcome separately.
 *
 * **A batch is not atomic**, deliberately: twenty-four machines are twenty-four independent
 * creations, and one refusal from the hypervisor must not undo the twenty-three that worked. So a
 * failure marks its own item and the loop continues - and "resume" then means something, because
 * the failed and the never-started items are distinguishable from the created ones.
 *
 * A ceiling on how many are launched in one pass, because a browser request is what triggers this
 * and twenty-four clones in one request is a request that times out. The screen resumes; pressing
 * it twice is safe by construction.
 *
 * The student's account is *recorded* here and created later, once the machine answers - the order
 * the design fixes and does not bend: clone → configure → start → reachable → accounts →
 * post-installation.
 */
class VmBatchExecutor
{
    /** How many machines one pass attempts. Chosen so a pass finishes inside a request. */
    public const int BATCH_SIZE = 5;

    public function __construct(
        private readonly GuestCreator $creator,
        private readonly IpAllocator $allocator,
        private readonly GuestAccountService $accounts,
        private readonly VmBatchItemRepository $items,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Attempts up to BATCH_SIZE outstanding items.
     *
     * @return array{attempted: int, created: int, failed: int, remaining: int}
     */
    public function run(VmBatch $batch, ?User $requestedBy): array
    {
        $outstanding = $this->items->findResumable($batch);
        $pass = \array_slice($outstanding, 0, self::BATCH_SIZE);

        $created = 0;
        $failed = 0;

        foreach ($pass as $item) {
            if ($this->deploy($batch, $item, $requestedBy)) {
                ++$created;
            } else {
                ++$failed;
            }
        }

        return [
            'attempted' => \count($pass),
            'created' => $created,
            'failed' => $failed,
            'remaining' => max(0, \count($outstanding) - \count($pass)),
        ];
    }

    private function deploy(VmBatch $batch, VmBatchItem $item, ?User $requestedBy): bool
    {
        $host = $batch->getHost();
        $range = $batch->getIpRange();

        if (null === $host || null === $range) {
            $item->setStatus(VmBatchItemStatus::Failed, 'The batch names no host or no address range.');
            $this->entityManager->flush();

            return false;
        }

        try {
            // Reserved per machine rather than all at once: a batch that fails halfway must not
            // hold the addresses of the machines it never created.
            $allocation = $this->allocator->reserveNext($range, hostname: $item->getGuestName());
        } catch (RangeExhaustedException|AddressUnavailableException $exception) {
            $item->setStatus(VmBatchItemStatus::Failed, $exception->getMessage());
            $this->entityManager->flush();

            return false;
        }

        $request = new GuestCreationRequest(
            hostname: $item->getGuestName(),
            vmid: $item->getVmid() ?? 0,
            node: $batch->getNode(),
            cores: $batch->getCores(),
            memoryMib: $batch->getMemoryMib(),
            diskGib: $batch->getDiskGib(),
            storage: $batch->getStorage(),
            range: $range,
            ip: $allocation->getIp(),
            sourceVmid: $batch->getTemplateVmid(),
            linkedClone: $batch->isLinkedClone(),
            isoVolumeId: null,
            startAfterCreation: true,
            postInstallScript: $batch->getPostInstallScript(),
        );

        try {
            $operation = $this->creator->create($host, $request, $allocation, $requestedBy);
        } catch (ProxmoxUnavailableException $exception) {
            // The creator has already released the address - a batch must not lose one per failure.
            $item->setStatus(VmBatchItemStatus::Failed, $exception->getMessage());
            $this->entityManager->flush();

            return false;
        }

        $item->setVmid($request->vmid);
        $item->setNode($request->node);
        $item->setIpAllocation($allocation);
        $item->setOperation($operation);
        $item->setStatus(VmBatchItemStatus::Created);

        // Recorded, not created: the machine is still being cloned. The accounts are laid down once
        // it answers, which is the whole reason the order is fixed.
        //
        // One account on a per-student machine, one per member on a per-group one - the loop is the
        // only thing that separates the two shapes here, because GuestAccount is keyed on
        // (host, node, vmid, login) and so already accepts several accounts on the same machine.
        foreach ($this->accountsFor($item) as $planned) {
            $account = $this->accounts->declare(
                $host,
                $request->node,
                $request->vmid,
                $planned['login'],
                GuestAccountOrigin::Member,
                $batch->isGrantSudo(),
                $planned['user'],
                $planned['label'],
            );
            $account->setBatch($batch);
        }

        $this->entityManager->flush();

        return true;
    }

    /**
     * Who gets an account on this machine.
     *
     * The per-group members are read from the item's own snapshot rather than from the set of
     * groups it came from: the set may have been deleted or re-saved since the plan, and the
     * machines must carry the accounts that were announced when the plan was shown. The user is
     * looked up by id only to attach a live account when there still is one - a member whose
     * account has since gone still gets their Unix login, under the name the plan recorded.
     *
     * @return list<array{login: string, label: string, user: ?User}>
     */
    private function accountsFor(VmBatchItem $item): array
    {
        $members = $item->getGroupMembers();

        if ([] === $members) {
            return [['login' => $item->getLogin(), 'label' => $item->getStudentLabel(), 'user' => $item->getStudent()]];
        }

        return array_map(
            fn (array $member): array => [
                'login' => $member['login'],
                'label' => $member['label'],
                'user' => $this->users->find($member['userId']),
            ],
            $members,
        );
    }
}
