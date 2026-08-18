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
 */
class ProxmoxOperationTracker
{
    /**
     * Beyond this, a task nobody could reach a verdict on is closed as unknown rather than left
     * polling for ever. Five minutes is the ceiling the design gives the Stimulus poller, so a row
     * outliving it has outlived the screen that was watching it.
     */
    private const int STALE_AFTER_SECONDS = 300;

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

        if ($operation->getStatus()->isSettled() || null === $upid || null === $node) {
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
                $operation->markUnknown('The task was still running after five minutes and is no longer followed.');
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
        $stale = $this->repository->findStale(new \DateTimeImmutable(\sprintf('-%d seconds', self::STALE_AFTER_SECONDS)));

        foreach ($stale as $operation) {
            $operation->markUnknown(
                ProxmoxOperationStatus::Pending === $operation->getStatus()
                    ? 'The request never reached the host, or its answer never came back.'
                    : 'The task was no longer followed after five minutes.',
            );
        }

        if ([] !== $stale) {
            $this->entityManager->flush();
        }

        return \count($stale);
    }

    private function isStale(ProxmoxOperation $operation): bool
    {
        return $operation->durationSeconds() > self::STALE_AFTER_SECONDS;
    }
}
