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
use App\Enum\VmInstallStep;
use App\Repository\UserRepository;
use App\Repository\VmBatchItemRepository;
use App\Service\Guest\GuestAccountService;
use App\Service\Guest\GuestCommandFailedException;
use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestTimeSync;
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
 * A ceiling on how many are launched in one pass, because a request is what triggers this and
 * twenty-four clones in one request is a request that times out. Pressing again is safe by
 * construction. And a second ceiling on how many machines are **under way** at once, which is not
 * the same thing at all: one item per pass, pressed as fast as the caller can press, is still
 * twenty-four machines being built at the same time - see MAX_IN_FLIGHT.
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
    /**
     * How many machines one pass attempts.
     *
     * One, deliberately: a machine at a time is what an administrator watching the screen can
     * follow. Since the pass takes whoever has waited longest rather than the first by position,
     * one per pass still advances the whole batch - it just does it in turn rather than five
     * abreast.
     *
     * It says nothing about how many machines are being built at once, which is MAX_IN_FLIGHT's
     * job - see there.
     */
    public const int BATCH_SIZE = 1;

    /**
     * How many machines may be under way at once - **under way meaning anywhere between the clone
     * request and the last line of the post-installation script**, not merely being cloned.
     *
     * One. The previous ceiling counted only the machines waiting on a clone, so the moment one
     * left that phase - cloned, configured, started, and still a minute away from answering on SSH
     * - the next clone was fired. Three or four machines were therefore always in the air at
     * different phases, which is exactly what "one at a time" was meant to prevent: a class deployed
     * that way asks the hypervisor to copy several disks while several others boot, nothing lands
     * quickly, and the screen gives up before the first machine is ready.
     *
     * A **failed** machine is deliberately not counted. It is not going to finish on its own, and
     * counting it would let one refusal hold the twenty-three that were doing fine - the very thing
     * a non-atomic batch exists to avoid. It stays in the queue and is re-attempted, in its turn.
     */
    public const int MAX_IN_FLIGHT = 1;

    /**
     * How long a machine may sit between "started" and "answers on SSH" before the batch gives up
     * on it, in seconds.
     *
     * A boot takes a minute, so not answering is a wait and not a failure - but nothing said when a
     * minute had become an afternoon, and provision() would have waited for ever. With one machine
     * deployed at a time, for ever means the whole class stops behind it. Fifteen minutes is well
     * past any real boot and well short of a lesson.
     */
    private const int MAX_BOOT_WAIT_SECONDS = 900;

    /**
     * How long a pass may spend before it stops starting new steps, in seconds.
     *
     * Chosen against PHP's `max_execution_time` of 30 seconds and not against the network. Five
     * machines that have been started but do not answer yet are the ordinary first minute of a
     * deployment, and each of them costs its own connection attempt: without a ceiling here the
     * pass is killed by the engine rather than returning, which is not a failure it can record -
     * it writes nothing at all. And since a pass always takes the *first* five resumable items, a
     * pass that never returns means the sixth machine onwards never starts.
     *
     * Fifteen leaves room for the step already under way to finish inside the limit, every
     * individual call being bounded on its own (ProxmoxClient's transport bounds, GuestSshSession's
     * connect budget).
     */
    private const float PASS_BUDGET_SECONDS = 15.0;

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
        private readonly GuestTimeSync $timeSync,
        private readonly UnixLogin $unixLogin,
        private readonly EntityManagerInterface $entityManager,
        // Injectable so a test can pin the guard without waiting for a real budget to run out.
        private readonly float $passBudgetSeconds = self::PASS_BUDGET_SECONDS,
    ) {
    }

    /**
     * Advances up to BATCH_SIZE outstanding items by one step each.
     *
     * `progressed` is what tells the screen whether to keep going or to slow down: a pass where
     * everything is merely waiting on a booting machine has moved nothing, and hammering the server
     * over it would be pure noise.
     *
     * **A pass also stops when its budget is spent**, and what it has not reached is a wait rather
     * than a failure: the screen simply comes back for it. See PASS_BUDGET_SECONDS for why a pass
     * that overruns is worse than a pass that does less.
     *
     * **Which items a pass takes is a matter of turns, not of position.** An item that is merely
     * waiting stays resumable, and a failed one is deliberately re-attempted, so choosing the first
     * BATCH_SIZE by position meant five machines that could not progress held every slot and the
     * sixth never started - the batch read as stuck at five. The repository now hands over the
     * items that have gone longest without a turn, never-attempted ones first.
     *
     * @return array{attempted: int, progressed: int, waiting: int, failed: int, remaining: int, blocked: int}
     */
    public function run(VmBatch $batch, ?User $requestedBy): array
    {
        $outstanding = $this->items->findResumable($batch);
        $pass = \array_slice($this->eligible($outstanding), 0, self::BATCH_SIZE);

        $progressed = 0;
        $waiting = 0;
        $failed = 0;

        $startedAt = microtime(true);

        foreach ($pass as $item) {
            // Checked before the step, never during: the steps are individually bounded, and
            // stopping between two of them leaves the queue exactly as it was.
            if (microtime(true) - $startedAt >= $this->passBudgetSeconds) {
                ++$waiting;

                continue;
            }

            // Stamped before the step, so an item that fails or throws still loses its turn: the
            // whole point is that no item can be picked twice while another has never been picked.
            $item->markAttempted();

            match ($this->advance($batch, $item, $requestedBy)) {
                self::PROGRESSED => ++$progressed,
                self::WAITING => ++$waiting,
                default => ++$failed,
            };
        }

        $remaining = $this->items->findResumable($batch);

        return [
            'attempted' => \count($pass),
            'progressed' => $progressed,
            'waiting' => $waiting,
            'failed' => $failed,
            'remaining' => \count($remaining),
            // What is left that pressing again will not move on its own. The screen needs the two
            // numbers side by side: it must keep going while machines are merely slow, and stop
            // once everything still outstanding has refused - without one failure ending the pass
            // for the twenty-three machines that were doing fine.
            'blocked' => \count(array_filter(
                $remaining,
                static fn (VmBatchItem $item): bool => VmBatchItemStatus::Failed === $item->getStatus(),
            )),
        ];
    }

    /**
     * The outstanding items a pass may actually take, in the order it should take them.
     *
     * Everything is eligible until MAX_IN_FLIGHT machines are already under way; from there the
     * pass may only advance one of those, never begin another. The batch does not stall for it -
     * the items it skips are the ones that have not begun, and they begin as soon as the machine
     * ahead of them is installed.
     *
     * What "begin another" means is read from the phase rather than from the status, and that is
     * the whole subtlety: an item that failed before its clone ever left is back on `Planned`, so
     * re-attempting it *is* starting a machine and has to wait its turn like any other. An item
     * that failed further along is not - advancing it costs the hypervisor nothing new.
     *
     * @param list<VmBatchItem> $outstanding
     *
     * @return list<VmBatchItem>
     */
    private function eligible(array $outstanding): array
    {
        $inFlight = 0;

        foreach ($outstanding as $item) {
            // The real status, not the phase: a failed machine is not under way, it is stopped.
            if (\in_array($item->getStatus(), [VmBatchItemStatus::Creating, VmBatchItemStatus::Created], true)) {
                ++$inFlight;
            }
        }

        if ($inFlight < self::MAX_IN_FLIGHT) {
            return $outstanding;
        }

        return array_values(array_filter(
            $outstanding,
            fn (VmBatchItem $item): bool => VmBatchItemStatus::Planned !== $this->phaseOf($item),
        ));
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
            $item->appendInstallLog(VmInstallStep::AddressUnavailable, $exception->getMessage(), ok: false);

            return $this->fail($item, $exception->getMessage());
        }

        $item->appendInstallLog(VmInstallStep::AddressReserved, $allocation->getIp());

        try {
            $operation = $this->creator->create($host, $this->requestFor($batch, $item, $allocation->getIp()), $allocation, $requestedBy);
        } catch (ProxmoxUnavailableException $exception) {
            // The creator has already released the address - a batch must not lose one per failure.
            $item->appendInstallLog(VmInstallStep::CloneFailed, $exception->getMessage(), ok: false);

            return $this->fail($item, $exception->getMessage());
        }

        $item->appendInstallLog(VmInstallStep::CloneRequested, \sprintf('%d → %d', $batch->getTemplateVmid(), $item->getVmid() ?? 0));
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
            if (\in_array($operation->getStatus(), [ProxmoxOperationStatus::Failed, ProxmoxOperationStatus::Unknown], true)) {
                $message = $operation->getMessage() ?? 'The creation task did not succeed.';
                $item->appendInstallLog(VmInstallStep::CloneFailed, $message, ok: false);

                return $this->fail($item, $message);
            }

            return $this->wait($item, null);
        }

        $item->appendInstallLog(VmInstallStep::CloneFinished);
        // Flushed on its own, before anything else is attempted: the configuration that follows is
        // where this pass is most likely to break, and a line written but never flushed is a log
        // that stops at « clonage demandé » while the machine has in fact been cloned. What the
        // reader needs first is to know which half of the step failed.
        $this->entityManager->flush();

        $allocation = $item->getIpAllocation();

        if (null === $allocation) {
            return $this->fail($item, 'The machine was created without an address.');
        }

        $request = $this->requestFor($batch, $item, $allocation->getIp());

        try {
            $keys = $this->creator->configureAndStart($host, $request);
        } catch (ProxmoxUnavailableException|\InvalidArgumentException $exception) {
            // \InvalidArgumentException covers what the configurator refuses before any call goes
            // out - a name that cannot be a hostname, an address that is not IPv4. Left to
            // propagate it would answer the pass with a 500, which the screen shows as a bare
            // warning and which writes nothing at all on the machine it concerns.
            $item->appendInstallLog(VmInstallStep::ConfigurationFailed, $exception->getMessage(), ok: false);

            return $this->fail($item, $exception->getMessage());
        }

        $item->appendInstallLog(VmInstallStep::Configured, \sprintf('%s / %s', $item->getGuestName(), $allocation->getIp()));
        // Named because it is what every later session logs in as: a machine nobody can reach is
        // answered by this line and the next one together.
        $item->appendInstallLog(VmInstallStep::AccountNamed, GuestShellFactory::SERVICE_ACCOUNT);
        // Named one by one: « I cannot log in » is answered by this line and nothing else.
        $item->appendInstallLog(VmInstallStep::KeysInstalled, [] === $keys ? null : implode(', ', $keys));

        if ($request->startAfterCreation) {
            $item->appendInstallLog(VmInstallStep::StartRequested);
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
            $item->appendInstallLog(VmInstallStep::Reachable, $allocation->getIp());
        } catch (GuestUnreachableException $exception) {
            // Recorded rather than only counted: this is the line somebody reads when a machine
            // never comes up, and the hypervisor's or SSH's own words are what points at the cause.
            $item->appendInstallLog(VmInstallStep::Unreachable, $exception->getMessage(), ok: false);

            // Past the ceiling it stops being a boot and becomes a machine that will not come up.
            // Left as a wait it would hold the whole batch behind it, one machine at a time being
            // the rule - so it is failed, and the next machine starts.
            if ($item->phaseDurationSeconds() > self::MAX_BOOT_WAIT_SECONDS) {
                return $this->fail($item, \sprintf(
                    'The machine did not answer within %d minutes of being started: %s',
                    intdiv(self::MAX_BOOT_WAIT_SECONDS, 60),
                    $exception->getMessage(),
                ));
            }

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
            $item->appendInstallLog(VmInstallStep::AccountsApplied, [] === $logins ? null : implode(', ', $logins));

            // Before the script rather than after: a script that fetches a package, checks a
            // certificate or writes a dated file wants the clock already right.
            $this->configureTimeSync($item, $shell, $batch);

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
                $item->appendInstallLog(VmInstallStep::PostInstallRun);
            }
        } catch (GuestCommandFailedException $exception) {
            // The machine answered and said no. Unlike being unreachable, trying again next pass
            // will not help: this is a failure, and the log carries the machine's own words.
            $item->appendInstallLog(VmInstallStep::AccountsFailed, $exception->getMessage(), ok: false);

            return $this->fail($item, $exception->getMessage());
        } catch (GuestUnreachableException $exception) {
            // Lost mid-way: the machine answered and then stopped. Still a wait - the next pass
            // finds the accounts it already created and only does what is left.
            $item->appendInstallLog(VmInstallStep::AccountsFailed, $exception->getMessage(), ok: false);

            return $this->wait($item, $exception->getMessage());
        } finally {
            $shell->disconnect();
        }

        $item->setStatus(VmBatchItemStatus::Provisioned);
        $this->entityManager->flush();

        return self::PROGRESSED;
    }

    /**
     * Points the machine's clock at the gateway of its own range.
     *
     * Recorded, never fatal. A school VLAN rarely lets a machine reach the public pool a cloud image
     * ships with, so this is what makes the clock right at all - but a machine whose clock is wrong
     * is still a machine the students can use, and failing it would hold the whole class behind a
     * template that is merely missing a package. The red line in the installation log is what stops
     * it being silent, which is the part that has cost time before.
     *
     * @throws GuestUnreachableException deliberately not caught: the machine going away mid-step is
     *                                   the caller's business, not this step's
     */
    private function configureTimeSync(VmBatchItem $item, GuestShell $shell, VmBatch $batch): void
    {
        $gateway = $batch->getIpRange()?->getGateway();

        if (null === $gateway || '' === $gateway) {
            return;
        }

        try {
            $this->timeSync->configure($shell, $gateway);
            $item->appendInstallLog(VmInstallStep::TimeSyncConfigured, $gateway);
        } catch (GuestCommandFailedException|\InvalidArgumentException $exception) {
            $item->appendInstallLog(VmInstallStep::TimeSyncFailed, $exception->getMessage(), ok: false);
        }
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
        // Only when it changes, and only when there is one: a clone that is simply still running
        // says nothing, and a hypervisor that has stopped answering says the same thing on every
        // pass. Without this the log stopped at « clonage demandé » no matter what was wrong.
        if (null !== $message && $message !== $item->getMessage()) {
            $item->appendInstallLog(VmInstallStep::Waiting, $message);
        }

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
