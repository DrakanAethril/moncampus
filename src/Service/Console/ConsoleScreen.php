<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Entity\ConsoleSession;
use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Repository\ConsoleSessionRepository;
use App\Repository\GuestAccountRepository;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxGuest;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the console screen is told, and what each answer of an exchange looks like.
 *
 * Kept out of the controller because the same six facts are assembled for both doors and because
 * every degraded state of §9 is a *sentence written for a person*, not an exception rendered:
 * « cette machine est éteinte » with the Start button at the same place as on the card, rather than
 * an SSH message about a refused connection. A console that fails by showing SSH's own words is a
 * console nobody opens twice.
 *
 * The hypervisor is asked for the machine's power state and is allowed to be down while asking:
 * **the console does not go through Proxmox at all**, so an unreachable host costs the Start button
 * and nothing else.
 *
 * @phpstan-type ConsoleAnswer array{ok: bool, state?: string, message?: string, digest?: string,
 *     pane?: string, cursorX?: int, cursorY?: int, columns?: int, rows?: int, alternate?: bool,
 *     canStart?: bool}
 */
class ConsoleScreen
{
    public function __construct(
        private readonly ConsoleSessionRepository $sessions,
        private readonly GuestAccountRepository $accounts,
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxInventory $inventory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The teacher's screen: the trail goes through « Mes machines virtuelles », because that is
     * where they came from and depth follows navigation.
     *
     * @return array<string, mixed>
     */
    public function forTeacher(ConsoleSession $session, GuestAccount $account): array
    {
        return $this->common($session) + [
            'account' => $account,
            'batchLabel' => $account->getBatch()?->getLabel(),
            'fromAdmin' => false,
        ];
    }

    /**
     * The logins declared on this machine - what « Devenir… » offers.
     *
     * Read from the accounts the platform created rather than from the machine's /etc/passwd: the
     * list is already known, it is the same one the card shows, and it excludes the system accounts
     * nobody means when they say « devenir un étudiant ».
     *
     * @return list<array{login: string, name: ?string}>
     */
    public function accountsOn(ConsoleSession $session): array
    {
        $host = $session->getHost();

        if (null === $host) {
            return [];
        }

        $logins = [];

        foreach ($this->accounts->findForMachine($host, $session->getNode(), $session->getVmid()) as $account) {
            $logins[] = ['login' => $account->getLogin(), 'name' => $account->getDisplayName()];
        }

        return $logins;
    }

    /**
     * The administrator's screen: same frame, deeper trail - Infrastructure › Hôtes › pve-01 ›
     * Machines › the machine.
     *
     * @return array<string, mixed>
     */
    public function forAdmin(ConsoleSession $session, ProxmoxHost $host, ?ProxmoxGuest $guest): array
    {
        return $this->common($session) + [
            'account' => null,
            'batchLabel' => null,
            'fromAdmin' => true,
            'host' => $host,
            'guest' => $guest,
        ];
    }

    /**
     * The refusal screens: a ceiling that names who holds the others, or a machine the platform has
     * no door on.
     *
     * @return array<string, mixed>
     */
    public function refusal(\RuntimeException $exception): array
    {
        return [
            'message' => $this->translator->trans($exception->getMessage()),
            'holders' => $exception instanceof ConsoleLimitReachedException ? $exception->holders : [],
        ];
    }

    /**
     * One screen, on its way back to the browser.
     *
     * @return ConsoleAnswer
     */
    public function pane(ConsoleSession $session, ConsolePane $pane): array
    {
        return [
            'ok' => true,
            'state' => 'live',
            'digest' => $pane->digest,
            'pane' => $pane->content,
            'cursorX' => $pane->cursorX,
            'cursorY' => $pane->cursorY,
            'columns' => $pane->columns,
            'rows' => $pane->rows,
            'alternate' => $pane->alternate,
            'unixUser' => $session->getUnixUser(),
        ];
    }

    /**
     * The machine did not answer, and the screen has to say *which* silence this is.
     *
     * Switched off is by far the commonest, and it is not an error: it is a machine somebody has to
     * start, and the button belongs on this screen at the same place as on the card. A machine that
     * is on but not answering yet is booting, and the browser simply tries again.
     *
     * @return ConsoleAnswer
     */
    public function unreachable(ConsoleSession $session, \RuntimeException $exception): array
    {
        if ($exception instanceof PlatformKeyUnavailableException) {
            // The frontier of the device, said in so many words: no platform key, no door. It is a
            // property of what MonCampus installed, not a breakdown.
            return ['ok' => false, 'state' => 'noDoor', 'message' => $this->translator->trans('consoleNoDoorMessage')];
        }

        $guest = $this->guestOf($session);

        if (null !== $guest && 'running' !== $guest->status) {
            return [
                'ok' => false,
                'state' => 'off',
                'message' => $this->translator->trans('consoleMachineOffMessage'),
                'canStart' => true,
            ];
        }

        return ['ok' => false, 'state' => 'booting', 'message' => $this->translator->trans('consoleMachineBootingMessage')];
    }

    /** @return array<string, mixed> */
    private function common(ConsoleSession $session): array
    {
        $host = $session->getHost();
        $guest = $this->guestOf($session);
        $others = null !== $host ? $this->sessions->findOthersOnMachine($session, $host, $session->getNode(), $session->getVmid()) : [];

        return [
            'session' => $session,
            'machineName' => $session->getGuestName() ?? $guest->name ?? \sprintf('VM %d', $session->getVmid()),
            'ip' => $session->getIp(),
            'unixUser' => $session->getUnixUser(),
            // Unknown rather than false when the hypervisor is down: a console still opens on a
            // machine nobody can ask about, and only « Démarrer » is unavailable.
            'running' => 'running' === $guest?->status ? true : (null === $guest ? null : false),
            'others' => $others,
            // A console that was already open, and how long ago - the resume banner exists because
            // closing the tab stops nothing, and somebody who is not told relaunches what is
            // already running.
            'resumedMinutes' => $session->openMinutes() >= 1 ? $session->openMinutes() : null,
            'machineAccounts' => $this->accountsOn($session),
        ];
    }

    private function guestOf(ConsoleSession $session): ?ProxmoxGuest
    {
        $host = $session->getHost();

        if (null === $host) {
            return null;
        }

        try {
            foreach ($this->inventory->guests($this->clientFactory->operate($host)) as $guest) {
                if ($guest->vmid === $session->getVmid() && $guest->node === $session->getNode()) {
                    return $guest;
                }
            }
        } catch (ProxmoxUnavailableException) {
            // Unknown, not broken - the console does not go through Proxmox.
            return null;
        }

        return null;
    }
}
