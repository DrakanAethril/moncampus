<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;

/**
 * What a pass of App\Service\Guest\StaleGuestAccountPruner found, said in three numbers rather than
 * one: what was removed, what was left alone because its machine is still there, and **what could
 * not be judged at all** because its hypervisor did not answer.
 *
 * That third number is the one worth printing. A pass that reads « 0 supprimé » on a host that was
 * down says nothing, and a pass that silently counted those as gone would delete a class's accounts
 * during a reboot.
 */
final readonly class StaleGuestAccountReport
{
    /**
     * @param list<GuestAccount> $stale     accounts whose machine a host that answered no longer holds
     * @param list<GuestAccount> $undecided accounts on a host that could not be read
     */
    public function __construct(
        public array $stale = [],
        public array $undecided = [],
        public int $keptCount = 0,
    ) {
    }
}
