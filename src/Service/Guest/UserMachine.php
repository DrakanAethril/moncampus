<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\GuestAccount;
use App\Enum\ProxmoxAction;

/**
 * One machine as its own user sees it: what it is called, where it answers, what it is made of,
 * who holds an account on it, and whether it is running.
 *
 * A read model rather than the entity, because most of it is not in the database. The status is
 * only ever true at the moment it is read, the size comes from the hypervisor, the address belongs
 * to the batch's item rather than to the account - and a template that had to go and fetch any of
 * them would put a Proxmox call inside a loop.
 *
 * **`$accounts` is every login registered on the machine, not the reader's own.** A machine of a
 * practical class is shared, and knowing who else is on it is how somebody works out which login is
 * theirs among four - see App\Repository\GuestAccountRepository::findLoginsOnMachines().
 */
final readonly class UserMachine
{
    /**
     * @param list<string>  $accounts     every login declared on this machine, alphabetical
     * @param ?ProxmoxAction $pendingAction a power action the hypervisor has not finished, which is
     *                                     what puts the card in its intermediate state
     */
    public function __construct(
        public GuestAccount $account,
        public string $name,
        public ?string $ip,
        /** `running`, `stopped`, or null when the hypervisor could not be asked. */
        public ?string $status,
        public ?string $batchLabel,
        public array $accounts = [],
        public ?int $memoryBytes = null,
        public ?int $diskBytes = null,
        public ?ProxmoxAction $pendingAction = null,
    ) {
    }

    public function isRunning(): bool
    {
        return 'running' === $this->status;
    }

    /** Whether anything can be done to it - a machine whose host is unreachable is not one to act on. */
    public function isKnown(): bool
    {
        return null !== $this->status;
    }

    /** Off, as the hypervisor sees it - which is not the same thing as "not running". */
    public function isStopped(): bool
    {
        return 'stopped' === $this->status;
    }

    /**
     * On its way from one state to the other, which the card draws in amber rather than as either
     * end of the journey.
     *
     * Which way it is going is read off an operation MonCampus itself opened, never guessed from
     * the status: a machine that is `stopped` because somebody just asked for it and a machine that
     * is `stopped` because it has been off since Friday look identical to the hypervisor.
     *
     * **Whether it is still going, though, is the hypervisor's word and not the operation's**, and
     * each of the two says half of it: the operation names the direction, the status says the
     * machine has not arrived yet. A machine that already answers `running` has started, whatever
     * its start task still says. So has a machine whose host cannot be asked at all - not started,
     * but not *starting* either: there is no evidence of anything under way, and « État inconnu »
     * is what the card then shows.
     *
     * The screen re-reads itself while any card is amber, which is why this matters beyond wording:
     * an operation that never settled - and until this screen resolved its own, none of a student's
     * ever did - used to reload the page for ever on a machine everybody else could see running.
     */
    public function isStarting(): bool
    {
        return ProxmoxAction::Start === $this->pendingAction && $this->isStopped();
    }

    public function isStopping(): bool
    {
        return \in_array($this->pendingAction, [ProxmoxAction::Shutdown, ProxmoxAction::Stop], true)
            && $this->isRunning();
    }

    public function isTransitioning(): bool
    {
        return $this->isStarting() || $this->isStopping();
    }

    /**
     * Whether its owner may set a password on it right now.
     *
     * Setting one needs SSH, which needs the machine up - and a machine on its way down is one the
     * session would be cut from halfway through.
     */
    public function acceptsPassword(): bool
    {
        return $this->isRunning() && !$this->isStopping();
    }
}
