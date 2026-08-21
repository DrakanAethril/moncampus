<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Entity\ConsoleBroadcast;
use App\Entity\ConsoleSession;
use App\Entity\User;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Repository\IpAllocationRepository;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One line, every machine of one batch, one result per machine.
 *
 * **The function that justifies the screen.** Installing a package on the eight machines of a
 * practical class is, today, eight terminals opened by hand.
 *
 * Three bounds, and each is a decision rather than a precaution:
 *
 *   - **One batch, never two.** The batch is read off the session's own account, not taken from the
 *     request: there is no shape of this feature in which somebody picks which class to send to.
 *   - **Never from a session that has become somebody else** (§7.4). What would go out is no longer
 *     the same command from one machine to the next.
 *   - **A line, not keystrokes.** One does not broadcast an interactive session - half the machines
 *     would be at a different prompt - one broadcasts a command.
 *
 * A machine that does not answer is **not a failure of the broadcast**: it is a switched-off
 * machine, and it comes back named and counted apart. The banner never collapses to « 7 / 8 ».
 *
 * @phpstan-import-type BroadcastResult from ConsoleBroadcast
 */
class ConsoleBroadcaster
{
    /**
     * Each machine is opened, written to and closed in turn, and one of them is allowed to be slow.
     *
     * Short on purpose: a broadcast is one HTTP request, and twenty-four machines at five seconds
     * each would be two minutes of a held worker. A machine that has not accepted an SSH session in
     * three seconds is a machine that is off or booting, which is exactly what the result says.
     */
    private const int PER_MACHINE_TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly GuestShellFactory $shellFactory,
        private readonly GuestPty $pty,
        private readonly IpAllocationRepository $allocations,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws ConsoleBroadcastRefusedException when this session may not broadcast at all
     */
    public function send(ConsoleSession $session, string $command, User $sentBy): ConsoleBroadcast
    {
        $command = trim($command);

        if ('' === $command) {
            throw new ConsoleBroadcastRefusedException('consoleBroadcastEmptyMessage');
        }

        if (ConsoleIdentity::PLATFORM_ACCOUNT !== $session->getUnixUser()) {
            throw new ConsoleBroadcastRefusedException('consoleBroadcastIdentityMessage');
        }

        $batch = $this->batchOf($session);
        $broadcast = new ConsoleBroadcast($session, $batch, $command, $sentBy);
        $broadcast->setResults($this->run($batch, $command));

        $this->entityManager->persist($broadcast);
        // Re-armed on every send: the ten-minute countdown restarts from the last thing sent, not
        // from the moment somebody pressed the button.
        $session->armBroadcast();
        $this->entityManager->flush();

        return $broadcast;
    }

    /**
     * The machines of the batch this console's account belongs to.
     *
     * @return list<VmBatchItem>
     *
     * @throws ConsoleBroadcastRefusedException
     */
    public function machinesOf(ConsoleSession $session): array
    {
        return $this->itemsOf($this->batchOf($session));
    }

    /** @throws ConsoleBroadcastRefusedException */
    public function batchOf(ConsoleSession $session): VmBatch
    {
        // Off the session's own account, never off the request: there is no shape of this feature
        // in which somebody chooses which class to send to.
        return $session->getGuestAccount()?->getBatch()
            ?? throw new ConsoleBroadcastRefusedException('consoleBroadcastNoBatchMessage');
    }

    /**
     * @return list<BroadcastResult>
     */
    private function run(VmBatch $batch, string $command): array
    {
        $results = [];

        foreach ($this->itemsOf($batch) as $item) {
            $vmid = $item->getVmid() ?? 0;
            $name = $item->getGuestName();
            $ip = $item->getIpAllocation()?->getIp() ?? $this->allocations->findAddressForVmid($vmid);

            if (null === $ip) {
                $results[] = ['vmid' => $vmid, 'name' => $name, 'ok' => false, 'message' => $this->translator->trans('consoleBroadcastNoAddressLabel')];

                continue;
            }

            $results[] = $this->sendTo($vmid, $name, $ip, $command);
        }

        return $results;
    }

    /** @return BroadcastResult */
    private function sendTo(int $vmid, string $name, string $ip, string $command): array
    {
        try {
            $shell = $this->shellFactory->open($ip, timeoutSeconds: self::PER_MACHINE_TIMEOUT_SECONDS);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException) {
            // Not an error of the broadcast: a machine that is off is a machine that is off, and
            // that is what the banner says, with its name.
            return ['vmid' => $vmid, 'name' => $name, 'ok' => false, 'message' => $this->translator->trans('consoleBroadcastUnreachableLabel')];
        }

        try {
            // Through the machine's own tmux, so the line lands in the same session a teacher would
            // see if they opened that machine's console next - and scrolls with it.
            $this->pty->ensure($shell, 120, 30);
            $this->pty->sendLine($shell, $command);
        } catch (ConsoleUnavailableException|GuestUnreachableException $exception) {
            return ['vmid' => $vmid, 'name' => $name, 'ok' => false, 'message' => $this->translator->trans($exception->getMessage())];
        } finally {
            $shell->disconnect();
        }

        return ['vmid' => $vmid, 'name' => $name, 'ok' => true];
    }

    /** @return list<VmBatchItem> */
    private function itemsOf(VmBatch $batch): array
    {
        $items = [];

        foreach ($batch->getItems() as $item) {
            if (null !== $item->getVmid()) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
