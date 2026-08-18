<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Entity\User;
use App\Enum\ProxmoxAction;
use Symfony\Component\Lock\LockFactory;

/**
 * Start, shut down, force off, reboot - the four actions the machines list offers, each of them
 * logged, scoped and serialised.
 *
 * Three things happen here that must not be skipped by a caller wiring the client directly:
 *
 *  1. **The perimeter is checked again.** The screen already hides what it must, but a POST is a
 *     POST: App\Service\Proxmox\ProxmoxScopeGuard is asked once more here, because this is the
 *     last place before the request leaves.
 *  2. **The log row is opened first** (App\Service\Proxmox\ProxmoxOperationTracker), so an action
 *     that never reaches the host still records who asked for it.
 *  3. **A lock is held per machine.** This is the repository's first use of symfony/lock, and the
 *     reason is concrete: two administrators on the machines list, one clicking « Arrêter » and
 *     the other « Forcer » within the same second, would send Proxmox two contradictory orders and
 *     leave two log rows both claiming to be the truth. The second click is refused rather than
 *     queued - waiting would just mean the power cut lands after the graceful shutdown began.
 *
 * Shutdown and stop are separate actions all the way down, never one call with a flag: an ACPI
 * request and a power cut are different acts, and the design is emphatic that they must not be
 * choosable by accident.
 */
class GuestPowerRunner
{
    public function __construct(
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxScopeGuard $scopeGuard,
        private readonly ProxmoxOperationTracker $tracker,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @throws ProxmoxUnavailableException when the perimeter refuses the action, when another
     *                                     administrator is already acting on this machine, or when
     *                                     the host cannot be reached
     */
    public function run(ProxmoxHost $host, ProxmoxGuest $guest, ProxmoxAction $action, ?User $requestedBy): ProxmoxOperation
    {
        if (!$action->isPowerAction()) {
            throw new ProxmoxUnavailableException(\sprintf('"%s" is not a power action.', $action->value));
        }

        $refusal = $this->scopeGuard->refusal(ProxmoxScope::fromHost($host), $action, $guest->vmid, $guest->pool);

        if (null !== $refusal) {
            throw new ProxmoxUnavailableException($refusal);
        }

        // A template is a disk image with a configuration, not a machine: Proxmox refuses to start
        // one with a message that reads like a bug. Refuse it here, in the application's words.
        if ($guest->template) {
            throw new ProxmoxUnavailableException('proxmoxRefusalTemplate');
        }

        $lock = $this->lockFactory->createLock(
            \sprintf('proxmox-guest-%d-%d', $host->getId() ?? 0, $guest->vmid),
            ttl: 60.0,
        );

        if (!$lock->acquire()) {
            throw new ProxmoxUnavailableException('proxmoxRefusalBusy');
        }

        try {
            return $this->post($host, $guest, $action, $requestedBy);
        } finally {
            $lock->release();
        }
    }

    private function post(ProxmoxHost $host, ProxmoxGuest $guest, ProxmoxAction $action, ?User $requestedBy): ProxmoxOperation
    {
        $operation = $this->tracker->begin(
            $host,
            $action,
            $requestedBy,
            $guest->node,
            $guest->vmid,
            $guest->name,
            $guest->type,
        );

        try {
            $client = $this->clientFactory->operate($host);
            $response = $client->post(\sprintf(
                '/nodes/%s/%s/%d/status/%s',
                rawurlencode($guest->node),
                $guest->endpointSegment(),
                $guest->vmid,
                $action->value,
            ));

            $this->tracker->accepted($operation, $response->scalar());

            return $operation;
        } catch (ProxmoxUnavailableException $exception) {
            // The row is already there and already says who asked; all that is added is why it did
            // not happen. Re-thrown so the screen shows the reason rather than a silent no-op.
            $this->tracker->failed($operation, $exception->getMessage());

            throw $exception;
        }
    }
}
