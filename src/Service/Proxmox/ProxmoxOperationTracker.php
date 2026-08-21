<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Entity\User;
use App\Enum\ProxmoxAction;
use App\Enum\ProxmoxOperationStatus;
use App\Repository\ProxmoxOperationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opens a log row before the request goes out, and closes it once Proxmox says how the task ended.
 *
 * The ordering is the design. `begin()` persists and flushes a `pending` row *before* anything is
 * sent, so a request that vanishes into a dead network still leaves a trace of who asked for it.
 * Writing the row after the answer would lose exactly the cases worth keeping.
 *
 * `resolve()` reads `/nodes/{node}/tasks/{upid}/status` and maps its two fields in the only order
 * that is correct: `status` says whether the task is over, and only once it is does `exitstatus`
 * say how. A task that is still running is not a failure, and neither is a host that stopped
 * answering - the latter settles to `unknown`, which is a first-class outcome here.
 *
 * The client is the caller's to choose, and it must be the one that **opened** the task: Proxmox
 * reads back your own tasks for free and charges `Sys.Audit` on `/nodes/<node>` for anybody else's.
 * App\Service\Proxmox\ProxmoxClientFactory::forAction() is what answers that, from the row's own
 * action.
 */
class ProxmoxOperationTracker
{
    /**
     * The shortest of the per-action ceilings, and the only thing this class still holds as a
     * number: it is the age below which no row can possibly be stale, which is what lets
     * settleStale() narrow the query before weighing each row against its own ceiling.
     *
     * The ceiling itself belongs to the action - see App\Enum\ProxmoxAction::maxTaskDurationSeconds().
     * A single five-minute value used to cover clones as well as power actions, and a clone of a
     * class's worth of machines is routinely longer than that: it declared healthy tasks `unknown`,
     * and an `unknown` creation fails the machine it belongs to for good.
     */
    private const int MIN_STALE_AFTER_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProxmoxOperationRepository $repository,
    ) {
    }

    /**
     * The row, at `pending`, flushed. Anything the caller does afterwards can fail without erasing
     * the fact that it was attempted.
     */
    public function begin(
        ProxmoxHost $host,
        ProxmoxAction $action,
        ?User $requestedBy,
        ?string $node = null,
        ?int $vmid = null,
        ?string $guestName = null,
        ?string $guestType = null,
    ): ProxmoxOperation {
        $operation = new ProxmoxOperation($host, $action, $requestedBy);

        if (null !== $node && null !== $vmid) {
            $operation->describeGuest($node, $vmid, $guestName, $guestType);
        }

        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    /** Proxmox accepted the request; from here on the UPID is what the answer will be found under. */
    public function accepted(ProxmoxOperation $operation, ?string $upid): void
    {
        if (null === $upid || '' === $upid) {
            // An action that answers no UPID has already finished - Proxmox does that for the
            // trivial ones. Nothing left to poll, so it is a success as of now.
            $operation->markSucceeded();
        } else {
            $operation->markRunning($upid);
        }

        $this->entityManager->flush();
    }

    public function failed(ProxmoxOperation $operation, string $message): void
    {
        $operation->markFailed($message);
        $this->entityManager->flush();
    }

    /**
     * Asks the host how the task went and records the verdict. Returns the operation for the
     * caller's convenience; the row is flushed either way.
     */
    public function resolve(ProxmoxOperation $operation, ProxmoxClient $client): ProxmoxOperation
    {
        $upid = $operation->getUpid();
        $node = $operation->getNode();

        if (null === $upid || null === $node || !$this->isWorthAsking($operation)) {
            return $operation;
        }

        try {
            $task = ProxmoxTask::fromRow(
                $client->get(\sprintf('/nodes/%s/tasks/%s/status', rawurlencode($node), rawurlencode($upid)))->row(),
                $upid,
            );
        } catch (ProxmoxUnavailableException $exception) {
            // The host answered the request and then went away. Neither success nor failure is
            // knowable, and the timeout below is what stops this row polling for ever.
            if ($this->isStale($operation)) {
                $operation->markUnknown($exception->getMessage());
                $this->entityManager->flush();
            }

            return $operation;
        }

        if (!$task->isFinished()) {
            if ($this->isStale($operation)) {
                $operation->markUnknown(\sprintf(
                    'The task was still running after %d seconds and is no longer followed.',
                    $operation->getAction()->maxTaskDurationSeconds(),
                ));
                $this->entityManager->flush();
            }

            return $operation;
        }

        if ($task->isSuccess()) {
            $operation->markSucceeded();
        } else {
            $operation->markFailed($task->failure());
        }

        $this->entityManager->flush();

        return $operation;
    }

    /**
     * Closes rows nobody is watching any more. Called by the journal screen rather than by a cron:
     * an operation left `running` for ever would otherwise read as "still going" months later.
     */
    public function settleStale(): int
    {
        $candidates = $this->repository->findStale(new \DateTimeImmutable(\sprintf('-%d seconds', self::MIN_STALE_AFTER_SECONDS)));
        $settled = 0;

        foreach ($candidates as $operation) {
            // Weighed against its own action's ceiling, never against the query's: the query only
            // narrows the rows worth looking at. Closing a clone here because a *start* would have
            // been overdue is what made merely opening this screen kill a class being deployed.
            if (!$this->isStale($operation)) {
                continue;
            }

            $operation->markUnknown(
                ProxmoxOperationStatus::Pending === $operation->getStatus()
                    ? 'The request never reached the host, or its answer never came back.'
                    : 'The task was no longer followed after its expected duration.',
            );
            ++$settled;
        }

        if ($settled > 0) {
            $this->entityManager->flush();
        }

        return $settled;
    }

    /**
     * Whether asking the host about this row can still tell us anything new.
     *
     * A settled row is normally the end of the story - except `unknown`, which is not a verdict but
     * the absence of one. **That is the difference between a batch that recovers and one that is
     * dead**: a clone declared unknown because nobody was watching long enough is very often a
     * clone that finished perfectly, and Proxmox still holds its task status. Refusing to ask again
     * froze the machine on a phase it could never leave - never configured, never started - while
     * the answer sat one GET away.
     */
    private function isWorthAsking(ProxmoxOperation $operation): bool
    {
        return !$operation->getStatus()->isSettled()
            || ProxmoxOperationStatus::Unknown === $operation->getStatus();
    }

    private function isStale(ProxmoxOperation $operation): bool
    {
        return $operation->durationSeconds() > $operation->getAction()->maxTaskDurationSeconds();
    }
}
