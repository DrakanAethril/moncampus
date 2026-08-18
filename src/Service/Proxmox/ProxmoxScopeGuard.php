<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Enum\ProxmoxAction;

/**
 * The application-side half of the perimeter.
 *
 * The perimeter is declared twice on purpose: as ACLs on a Proxmox pool, and again here. The point
 * of the second declaration is that a Proxmox account granted more than it should be does not
 * become a way to touch a machine MonCampus was never meant to touch - a bug or a misconfiguration
 * on one side is caught by the other.
 *
 * Three questions, kept apart because they fail differently:
 *
 *   covers()       - is this machine inside the perimeter at all? A machine outside it stays
 *                    **visible and counted** and simply cannot be acted upon. Hiding it would make
 *                    an administrator think it had vanished from the hypervisor.
 *   allows()       - and does the host permit this kind of action?
 *   quotaRefusal() - and does what is being asked for fit the declared ceilings?
 *
 * Written on primitives (App\Service\Proxmox\ProxmoxScope), never on the entity, so it could be
 * tested before it existed.
 */
class ProxmoxScopeGuard
{
    /**
     * @param string|null $pool the pool the guest belongs to, as `/cluster/resources` reports it -
     *                          null means it is in none
     */
    public function covers(ProxmoxScope $scope, int $vmid, ?string $pool): bool
    {
        // A declared pool is a requirement, so a guest in no pool at all fails it. That is not
        // over-strict: "outside the managed pool" is exactly what a guest with no pool is.
        if (null !== $scope->managedPool && $pool !== $scope->managedPool) {
            return false;
        }

        // Each bound guards on its own. A floor with no ceiling ("everything from 200 up") is a
        // legitimate declaration and must not degrade into "everything".
        if (null !== $scope->vmidMin && $vmid < $scope->vmidMin) {
            return false;
        }

        return null === $scope->vmidMax || $vmid <= $scope->vmidMax;
    }

    public function allows(ProxmoxScope $scope, ProxmoxAction $action, int $vmid, ?string $pool): bool
    {
        return null === $this->refusal($scope, $action, $vmid, $pool);
    }

    /**
     * Why the action is refused, as a translation key - or null when it is not.
     *
     * Being out of scope outranks a switch being off, because that is the more useful thing to
     * report: turning the switch on would not help.
     */
    public function refusal(ProxmoxScope $scope, ProxmoxAction $action, int $vmid, ?string $pool): ?string
    {
        if (!$this->covers($scope, $vmid, $pool)) {
            return 'proxmoxRefusalOutOfScope';
        }

        $permitted = match ($action->requiredPermission()) {
            'start' => $scope->allowStart,
            'stop' => $scope->allowStop,
            'create' => $scope->allowCreate,
            default => true,
        };

        return $permitted ? null : 'proxmoxRefusalActionNotAllowed';
    }

    /**
     * Whether a creation fits the host's ceilings, as a translation key - or null.
     *
     * $requested is how many machines are being asked for at once, which is what makes this usable
     * for a batch: twenty-four requests that each fit the guest ceiling and together do not is
     * precisely the case a per-machine check misses.
     *
     * Every ceiling is inclusive: `maxCores: 4` means four cores are allowed, not three.
     */
    public function quotaRefusal(
        ProxmoxScope $scope,
        int $cores,
        int $memoryMib,
        int $diskGib,
        int $currentGuestCount,
        int $requested = 1,
    ): ?string {
        if (null !== $scope->maxCores && $cores > $scope->maxCores) {
            return 'proxmoxRefusalTooManyCores';
        }

        if (null !== $scope->maxMemoryMib && $memoryMib > $scope->maxMemoryMib) {
            return 'proxmoxRefusalTooMuchMemory';
        }

        if (null !== $scope->maxDiskGib && $diskGib > $scope->maxDiskGib) {
            return 'proxmoxRefusalTooMuchDisk';
        }

        if (null !== $scope->maxGuests && $currentGuestCount + $requested > $scope->maxGuests) {
            return 'proxmoxRefusalTooManyGuests';
        }

        return null;
    }
}
