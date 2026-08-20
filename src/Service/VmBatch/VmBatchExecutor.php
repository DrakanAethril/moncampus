<?php

declare(strict_types=1);

namespace App\Service\VmBatch;

use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\GuestAccountOrigin;
use App\Enum\ProxmoxOperationStatus;
use App\Enum\VmBatchItemStatus;
use App\Repository\UserRepository;
use App\Repository\VmBatchItemRepository;
use App\Service\Guest\GuestAccountService;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\Guest\PostInstallRunner;
use App\Service\Guest\UnixLogin;
use App\Service\Network\AddressUnavailableException;
use App\Service\Network\IpAllocator;
use App\Service\Network\RangeExhaustedException;
use App\Service\Proxmox\GuestCreationRequest;
use App\Service\Proxmox\GuestCreator;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxOperationTracker;
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
 * **A pass advances each item by one step, it does not finish it.** The order the design fixes and
 * does not bend is clone → configure → start → reachable → accounts → post-installation, and three
 * of those steps are waits: Proxmox answers a clone with a task id and finishes it in its own time,
 * and a freshly started machine takes a minute to answer on SSH. Blocking a request until a machine
 * boots is not an option, so an item's status *is* the phase it has reached, and the screen keeps
 * pressing until nothing moves:
 *
 * | Phase         | What the pass does                                    | Then          |
 * |---------------|-------------------------------------------------------|---------------|
 * | `Planned`     | reserve an address, ask Proxmox to clone              | `Creating`    |
 * | `Creating`    | poll the clone task; when done, configure and start   | `Created`     |
 * | `Created`     | try SSH; when it answers, lay down accounts + script  | `Provisioned` |
 *
 * A step that cannot happen *yet* leaves the item where it is and reports a wait, never a failure:
 * a machine that has not booted is the normal case, not an error. Only a refusal fails.
 *
 * The accounts are created with a password that is generated, sent to the machine and forgotten on
 * the spot - never displayed, never stored (PasswordGenerator::generateStrong()). Until a student
 * is given a way to set their own, the accounts exist but nobody can log into them by password;
 * MonCampus itself keeps root access through the platform key, which is what a later "set my
 * password" screen will use.
 */
class VmBatchExecutor
{
    /** How many machines one pass attempts. Chosen so a pass finishes inside a request. */
    public const int BATCH_SIZE = 5;

    private const string PROGRESSED = 'progressed';
    private const string WAITING = 'waiting';
    private const string FAILED = 'failed';

    public function __construct(
        private readonly GuestCreator $creator,
        private readonly IpAllocator $allocator,
        private readonly GuestAccountService $accounts,
        private readonly VmBatchItemRepository $items,
        private readonly UserRepository $users,
        private readonly ProxmoxOperationTracker $tracker,
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly GuestShellFactory $shellFactory,
        private readonly PostInstallRunner $postInstall,
        private readonly UnixLogin $unixLogin,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Advances up to BATCH_SIZE outstanding items by one step each.
     *
     * `progressed` is what tells the screen whether to keep going or to slow down: a pass where
     * everything is merely waiting on a booting machine has moved nothing, and hammering the server
     * over it would be pure noise.
     *
     * @return array{attempted: int, progressed: int, waiting: int, failed: int, remaining: int}
     */
    public function run(VmBatch $batch, ?User $requestedBy): array
    {
        $outstanding = $this->items->findResumable($batch);
        $pass = \array_slice($outstanding, 0, self::BATCH_SIZE);

        $progressed = 0;
        $waiting = 0;
        $failed = 0;

        foreach ($pass as $item) {
            match ($this->advance($batch, $item, $requestedBy)) {
                self::PROGRESSED => ++$progressed,
                self::WAITING => ++$waiting,
                default => ++$failed,
            };
        }

        return [
            'attempted' => \count($pass),
            'progressed' => $progressed,
            'waiting' => $waiting,
            'failed' => $failed,
            'remaining' => \count($this->items->findResumable($batch)),
        ];
    }

    /**
     * One step for one item, and one only.
     *
     * A failure does not say which step it was, so a Failed item is placed back on the phase its own
     * evidence supports: no operation at all means the clone never left, and is re-attempted. A
     * clone task that Proxmox itself reported as failed is NOT re-attempted - the VMID may carry a
     * half-built machine, and cloning onto it again would fail differently. That one waits for
     * somebody to look.
     */
    private function advance(VmBatch $batch, VmBatchItem $item, ?User $requestedBy): string
    {
        $host = $batch->getHost();
        $range = $batch->getIpRange();

        if (null === $host || null === $range) {
            return $this->fail($item, 'The batch names no host or no address range.');
        }

        return match ($this->phaseOf($item)) {
            VmBatchItemStatus::Creating => $this->settle($batch, $item, $requestedBy),
            VmBatchItemStatus::Created => $this->provision($batch, $item, $requestedBy),
            default => $this->launch($batch, $item, $requestedBy),
        };
    }

    private function phaseOf(VmBatchItem $item): VmBatchItemStatus
    {
        if (VmBatchItemStatus::Failed !== $item->getStatus()) {
            return $item->getStatus();
        }

        $operation = $item->getOperation();

        if (null === $operation) {
            return VmBatchItemStatus::Planned;
        }

        return ProxmoxOperationStatus::Succeeded === $operation->getStatus()
            ? VmBatchItemStatus::Created
            : VmBatchItemStatus::Creating;
    }

    /** Reserve an address and ask Proxmox to clone. Returns as soon as the task is accepted. */
    private function launch(VmBatch $batch, VmBatchItem $item, ?User $requestedBy): string
    {
        $host = $batch->getHost();
        $range = $batch->getIpRange();

        if (null === $host || null === $range) {
            return $this->fail($item, 'The batch names no host or no address range.');
        }

        try {
            // Reserved per machine rather than all at once: a batch that fails halfway must not
            // hold the addresses of the machines it never created.
            $allocation = $this->allocator->reserveNext($range, hostname: $item->getGuestName());
        } catch (RangeExhaustedException|AddressUnavailableException $exception) {
            return $this->fail($item, $exception->getMessage());
        }

        try {
            $operation = $this->creator->create($host, $this->requestFor($batch, $item, $allocation->getIp()), $allocation, $requestedBy);
        } catch (ProxmoxUnavailableException $exception) {
            // The creator has already released the address - a batch must not lose one per failure.
            return $this->fail($item, $exception->getMessage());
        }

        $item->setNode($batch->getNode());
        $item->setIpAllocation($allocation);
        $item->setOperation($operation);
        $item->setStatus(VmBatchItemStatus::Creating);
        $this->entityManager->flush();

        return self::PROGRESSED;
    }

    /**
     * Ask Proxmox whether the clone is done, and configure and start the machine the moment it is.
     *
     * The order matters and is not ours to choose: cloud-init writes its configuration at the first
     * boot and never again, so the network PUT has to land before the machine is started - which is
     * why this cannot be folded into launch() and fired straight after the clone call.
     */
    private function settle(VmBatch $batch, VmBatchItem $item, ?User $requestedBy): string
    {
        $host = $batch->getHost();
        $operation = $item->getOperation();

        if (null === $host || null === $operation) {
            return $this->fail($item, 'The machine has no creation task to wait on.');
        }

        try {
            // The clone was opened by the provisioning account, so it is the one that may ask how
            // it went: Proxmox answers 403 (Sys.Audit) to an account reading another's task.
            $operation = $this->tracker->resolve($operation, $this->clientFactory->forAction($host, $operation->getAction()));
        } catch (ProxmoxUnavailableException $exception) {
            // The hypervisor being unreachable says nothing about the task - keep waiting.
            return $this->wait($item, $exception->getMessage());
        }

        if (ProxmoxOperationStatus::Succeeded !== $operation->getStatus()) {
            return match ($operation->getStatus()) {
                ProxmoxOperationStatus::Failed, ProxmoxOperationStatus::Unknown => $this->fail($item, $operation->getMessage() ?? 'The creation task did not succeed.'),
                default => $this->wait($item, null),
            };
        }

        $allocation = $item->getIpAllocation();

        if (null === $allocation) {
            return $this->fail($item, 'The machine was created without an address.');
        }

        try {
            $this->creator->configureAndStart($host, $this->requestFor($batch, $item, $allocation->getIp()));
        } catch (ProxmoxUnavailableException $exception) {
            return $this->fail($item, $exception->getMessage());
        }

        $item->setStatus(VmBatchItemStatus::Created);
        $this->entityManager->flush();

        return self::PROGRESSED;
    }

    /**
     * The machine exists and has been started: wait for it to answer, then lay down the accounts and
     * run the post-installation script.
     *
     * Not answering is a wait rather than a failure - a machine that has just been started takes a
     * minute to bring SSH up, and calling that an error would fail every batch on its first pass.
     */
    private function provision(VmBatch $batch, VmBatchItem $item, ?User $requestedBy): string
    {
        $host = $batch->getHost();
        $allocation = $item->getIpAllocation();
        $vmid = $item->getVmid();

        if (null === $host || null === $allocation || null === $vmid) {
            return $this->fail($item, 'The machine has no address to be reached at.');
        }

        // A login the platform holds and useradd would refuse must stop the machine here, loudly.
        // GuestAccountSyncer skips such an account with a `continue` - which on this path would mean
        // a student standing in front of a machine that has no account for them, and nothing
        // anywhere saying why.
        $unusable = $this->unusableLogins($item);

        if ([] !== $unusable) {
            return $this->fail($item, \sprintf(
                'These platform logins cannot be Unix accounts: %s. A login must be lowercase letters, digits and hyphens, start with a letter and be at most 32 characters.',
                implode(', ', $unusable),
            ));
        }

        // Declared first and every time: re-running this after a member was added to the machine
        // creates the missing account and leaves the others alone.
        $this->declareAccounts($batch, $item, $host, $item->getNode() ?? $batch->getNode(), $vmid);

        try {
            $shell = $this->shellFactory->open($allocation->getIp());
        } catch (GuestUnreachableException $exception) {
            return $this->wait($item, $exception->getMessage());
        } catch (PlatformKeyUnavailableException $exception) {
            // Not a wait: no platform key means no machine will ever be reachable, and saying
            // "en attente" about it would hide a configuration problem behind a spinner.
            return $this->fail($item, $exception->getMessage());
        }

        try {
            $plan = $this->accounts->refresh($shell, $host, $item->getNode() ?? $batch->getNode(), $vmid);
            // readAloud: false - the passwords are generated, sent to the machine and dropped here.
            // Only the logins are kept, and only to hand them to the post-installation script.
            $applied = $this->accounts->apply($shell, $host, $item->getNode() ?? $batch->getNode(), $vmid, $item->getGuestName(), $plan, $requestedBy, readAloud: false);
            $logins = array_keys($applied['passwords']);

            $script = $batch->getPostInstallScript();

            if (null !== $script && '' !== trim($script)) {
                $this->postInstall->run(
                    $shell,
                    $host,
                    $item->getNode() ?? $batch->getNode(),
                    $vmid,
                    $item->getGuestName(),
                    $allocation->getIp(),
                    $script,
                    $logins,
                    $requestedBy,
                    $batch->getLabel(),
                );
            }
        } catch (GuestUnreachableException $exception) {
            // Lost mid-way: the machine answered and then stopped. Still a wait - the next pass
            // finds the accounts it already created and only does what is left.
            return $this->wait($item, $exception->getMessage());
        } finally {
            $shell->disconnect();
        }

        $item->setStatus(VmBatchItemStatus::Provisioned);
        $this->entityManager->flush();

        return self::PROGRESSED;
    }

    /** @return list<string> */
    private function unusableLogins(VmBatchItem $item): array
    {
        $unusable = [];

        foreach ($this->accountsFor($item) as $planned) {
            if (!$this->unixLogin->isValid($planned['login'])) {
                $unusable[] = $planned['login'];
            }
        }

        return $unusable;
    }

    private function declareAccounts(VmBatch $batch, VmBatchItem $item, ProxmoxHost $host, string $node, int $vmid): void
    {
        // One account on a per-student machine, one per member on a per-group one - the loop is the
        // only thing that separates the two shapes here, because GuestAccount is keyed on
        // (host, node, vmid, login) and so already accepts several accounts on the same machine.
        foreach ($this->accountsFor($item) as $planned) {
            $account = $this->accounts->declare(
                $host,
                $node,
                $vmid,
                $planned['login'],
                GuestAccountOrigin::Member,
                $batch->isGrantSudo(),
                $planned['user'],
                $planned['label'],
            );
            $account->setBatch($batch);
        }

        $this->entityManager->flush();
    }

    private function requestFor(VmBatch $batch, VmBatchItem $item, string $ip): GuestCreationRequest
    {
        $range = $batch->getIpRange();

        if (null === $range) {
            throw new \LogicException('A batch without an address range never reaches here.');
        }

        return new GuestCreationRequest(
            hostname: $item->getGuestName(),
            vmid: $item->getVmid() ?? 0,
            node: $item->getNode() ?? $batch->getNode(),
            cores: $batch->getCores(),
            memoryMib: $batch->getMemoryMib(),
            diskGib: $batch->getDiskGib(),
            storage: $batch->getStorage(),
            range: $range,
            ip: $ip,
            sourceVmid: $batch->getTemplateVmid(),
            linkedClone: $batch->isLinkedClone(),
            isoVolumeId: null,
            startAfterCreation: true,
            postInstallScript: $batch->getPostInstallScript(),
        );
    }

    /**
     * Leaves the item on its phase and says why, so the screen shows a wait rather than a stall.
     *
     * The status is re-set to the one it already has because that is what carries the message -
     * VmBatchItem has no separate setter, deliberately: a message without a status it belongs to is
     * how stale explanations survive their cause.
     */
    private function wait(VmBatchItem $item, ?string $message): string
    {
        $item->setStatus($item->getStatus(), $message);
        $this->entityManager->flush();

        return self::WAITING;
    }

    private function fail(VmBatchItem $item, string $message): string
    {
        $item->setStatus(VmBatchItemStatus::Failed, $message);
        $this->entityManager->flush();

        return self::FAILED;
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
