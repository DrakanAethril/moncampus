<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Entity\ConsoleSession;
use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Entity\User;
use App\Enum\ProxmoxAction;
use App\Repository\ConsoleSessionRepository;
use App\Repository\IpAllocationRepository;
use App\Repository\VmBatchItemRepository;
use App\Service\Proxmox\ProxmoxOperationTracker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that happens between « ouvrir une console » and there being one to talk to, except the
 * talking.
 *
 * Four things, in this order and for a reason each:
 *
 *   1. **Idle rows are closed first.** A console nobody has typed into for fifteen minutes is a
 *      laptop that was shut, and counting it against the ceiling would let yesterday's tabs lock
 *      today's class out. Nothing is killed inside the machine - closing a row ends a trace, not a
 *      shell, and the tmux carries on exactly as the design intends.
 *   2. **The ceiling.** Four at a time, and the refusal names who holds the others.
 *   3. **The address**, resolved the same way « Mes machines virtuelles » resolves it: the batch's
 *      own allocation first, the address registry as the fallback. A card showing one address while
 *      the console opened on another is a bug nobody can see.
 *   4. **The row**, reused rather than recreated when one is already open on this machine for this
 *      person - which is what makes « session reprise » a fact and not a promise.
 *
 * Nothing here decides *who may*. That is the voter's on one door and access_control's on the
 * other, and this service is reached only once one of them has answered yes.
 */
class ConsoleSessionOpener
{
    public function __construct(
        private readonly ConsoleSessionRepository $sessions,
        private readonly IpAllocationRepository $allocations,
        private readonly VmBatchItemRepository $items,
        private readonly ProxmoxOperationTracker $tracker,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $consoleMaxSessions,
    ) {
    }

    /**
     * The teacher's door: a console on the machine an account sits on.
     *
     * @throws ConsoleLimitReachedException
     * @throws ConsoleAddressUnknownException
     */
    public function openForAccount(GuestAccount $account, User $openedBy): ConsoleSession
    {
        $host = $account->getHost() ?? throw new ConsoleAddressUnknownException('consoleNoMachineMessage');

        $session = $this->open($host, $account->getNode(), $account->getVmid(), $openedBy);
        $session->setGuestAccount($account);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * The administrator's door: a console on any machine of the managed perimeter.
     *
     * @throws ConsoleLimitReachedException
     * @throws ConsoleAddressUnknownException
     */
    public function openForMachine(ProxmoxHost $host, string $node, int $vmid, User $openedBy): ConsoleSession
    {
        return $this->open($host, $node, $vmid, $openedBy);
    }

    /** Ends the trace. The shell in the machine is deliberately left running. */
    public function close(ConsoleSession $session): void
    {
        $session->close();
        $this->entityManager->flush();
    }

    /**
     * @throws ConsoleLimitReachedException
     * @throws ConsoleAddressUnknownException
     */
    private function open(ProxmoxHost $host, string $node, int $vmid, User $openedBy): ConsoleSession
    {
        $open = $this->closeIdleAndListOpen();
        $existing = $this->sessions->findOpenFor($openedBy, $host, $node, $vmid);

        if (null !== $existing) {
            // Rejoining costs nothing and counts for nothing: it is the same console, the same
            // tmux and the same worker budget as before.
            return $this->refresh($existing, $host, $node, $vmid);
        }

        if (\count($open) >= $this->consoleMaxSessions) {
            throw new ConsoleLimitReachedException($this->describe($open));
        }

        $ip = $this->addressOf($host, $vmid);

        if (null === $ip) {
            throw new ConsoleAddressUnknownException('consoleNoAddressMessage');
        }

        $session = new ConsoleSession($host, $node, $vmid, $ip, $openedBy, ConsoleIdentity::PLATFORM_ACCOUNT);
        $name = $this->nameOf($host, $vmid);
        $session->setGuestName($name);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        // In the operations journal, at the same place as a start or a shutdown: it is the same
        // question - who asked what of which machine - and a console is the most far-reaching of
        // the answers, since it opens on an account that has sudo. Written at the opening rather
        // than at the closing, because a console that was opened and then went wrong still happened.
        $this->tracker->begin($host, ProxmoxAction::Console, $openedBy, $node, $vmid, $name)->markSucceeded();
        $this->entityManager->flush();

        return $session;
    }

    /**
     * A rejoined session may find its machine has moved: the address is re-read, the name with it.
     *
     * Frozen at open time and refreshed at re-open is the honest middle - the row says where this
     * console actually went, and a machine that got a new address is not a console that must fail.
     */
    private function refresh(ConsoleSession $session, ProxmoxHost $host, string $node, int $vmid): ConsoleSession
    {
        $ip = $this->addressOf($host, $vmid);

        if (null !== $ip && $ip !== $session->getIp()) {
            $session->setIp($ip);
        }

        $session->touch();
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Closes what has gone quiet and hands back what is genuinely still open.
     *
     * @return list<ConsoleSession>
     */
    private function closeIdleAndListOpen(): array
    {
        $now = new \DateTimeImmutable();
        $live = [];
        $changed = false;

        foreach ($this->sessions->findAllOpen() as $session) {
            if ($session->isIdle($now)) {
                $session->close();
                $changed = true;

                continue;
            }

            $live[] = $session;
        }

        if ($changed) {
            $this->entityManager->flush();
        }

        return $live;
    }

    /**
     * @param list<ConsoleSession> $open
     *
     * @return list<string>
     */
    private function describe(array $open): array
    {
        $byPerson = [];

        foreach ($open as $session) {
            $who = $session->getOpenedBy();
            $name = $who?->getDisplayName() ?? $who?->getUsername() ?? '?';
            $byPerson[$name][] = $session->getGuestName() ?? \sprintf('VM %d', $session->getVmid());
        }

        $lines = [];

        foreach ($byPerson as $name => $machines) {
            $lines[] = \sprintf('%s (%s)', $name, implode(', ', $machines));
        }

        return $lines;
    }

    /** The batch's own allocation first, the address registry as the fallback. */
    private function addressOf(ProxmoxHost $host, int $vmid): ?string
    {
        return $this->items->findOneForMachine($host, $vmid)?->getIpAllocation()?->getIp()
            ?? $this->allocations->findAddressForVmid($vmid);
    }

    private function nameOf(ProxmoxHost $host, int $vmid): ?string
    {
        return $this->items->findOneForMachine($host, $vmid)?->getGuestName();
    }
}
