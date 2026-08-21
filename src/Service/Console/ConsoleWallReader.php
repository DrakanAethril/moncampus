<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Entity\VmBatchItem;
use App\Repository\IpAllocationRepository;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;

/**
 * One tile of the console wall: the last few lines of one machine's screen.
 *
 * « Où en sont-ils ? » gets asked twenty times a session and has, today, no answer at all. The wall
 * is that answer, and it is **read-only**: one looks at the class, one does not type into it.
 *
 * **One tile per request**, and that is what keeps it affordable without adding anything: the
 * browser asks for four at a time on a fifteen-second cycle, so twenty-four tiles take about two
 * seconds of wall, each request is short, and the workers keep turning. A single request drawing
 * the whole wall would hold one worker for the sum of twenty-four SSH handshakes.
 *
 * A tile that cannot be reached says « injoignable » and does not stop the seven others - the same
 * rule as the machines list, which does not refuse to draw itself because one host is down.
 */
class ConsoleWallReader
{
    /** How many lines of the bottom of the screen a tile shows. */
    public const int TILE_LINES = 6;

    /** Short: a tile is a glance, and a machine that has not answered in four seconds is off. */
    private const int TIMEOUT_SECONDS = 6;

    public function __construct(
        private readonly GuestShellFactory $shellFactory,
        private readonly GuestPty $pty,
        private readonly IpAllocationRepository $allocations,
    ) {
    }

    /** @return array{ok: bool, name: string, vmid: int, lines: string, state: string} */
    public function read(VmBatchItem $item): array
    {
        $vmid = $item->getVmid() ?? 0;
        $name = $item->getGuestName();
        $ip = $item->getIpAllocation()?->getIp() ?? $this->allocations->findAddressForVmid($vmid);

        if (null === $ip) {
            return ['ok' => false, 'name' => $name, 'vmid' => $vmid, 'lines' => '', 'state' => 'unknown'];
        }

        try {
            $shell = $this->shellFactory->open($ip, timeoutSeconds: self::TIMEOUT_SECONDS);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException) {
            return ['ok' => false, 'name' => $name, 'vmid' => $vmid, 'lines' => '', 'state' => 'off'];
        }

        try {
            // Never ensure() here: a tile must not install anything on a machine nobody has opened
            // a console on. A machine with no tmux simply has nothing to show yet, which is honest.
            $pane = $this->pty->snapshot($shell, 120, 30);
        } catch (ConsoleNotReadyException) {
            return ['ok' => false, 'name' => $name, 'vmid' => $vmid, 'lines' => '', 'state' => 'idle'];
        } catch (GuestUnreachableException) {
            return ['ok' => false, 'name' => $name, 'vmid' => $vmid, 'lines' => '', 'state' => 'off'];
        } finally {
            $shell->disconnect();
        }

        $lines = array_filter(explode("\n", $pane->plainText()), static fn (string $line): bool => '' !== trim($line));

        return [
            'ok' => true,
            'name' => $name,
            'vmid' => $vmid,
            'lines' => implode("\n", \array_slice(array_values($lines), -self::TILE_LINES)),
            'state' => 'running',
        ];
    }
}
