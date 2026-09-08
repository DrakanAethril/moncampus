<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Entity\GuestAccount;
use App\Entity\ProxmoxHost;
use App\Enum\ProxmoxAction;
use App\Service\Guest\UserMachine;
use PHPUnit\Framework\TestCase;

/**
 * The amber state of a card on « Mes machines virtuelles », which is not a wording question: the
 * screen reloads itself for as long as any card is in it, so a machine that can never leave the
 * state is a page that reloads for ever.
 *
 * The rule takes both halves - the operation says which way the machine is going, the hypervisor
 * says whether it has arrived - and it is the second half these tests are about. A start task that
 * Proxmox holds open, or a host that stops answering, must not outvote a machine that is up.
 */
class UserMachineTest extends TestCase
{
    public function testAMachineWithNothingUnderWayIsNotTransitioning(): void
    {
        self::assertFalse($this->machine('running')->isTransitioning());
        self::assertFalse($this->machine('stopped')->isTransitioning());
    }

    public function testAStartIsUnderWayWhileTheMachineIsStillOff(): void
    {
        $machine = $this->machine('stopped', ProxmoxAction::Start);

        self::assertTrue($machine->isStarting());
        self::assertTrue($machine->isTransitioning());
    }

    /** The bug this class was tightened for: the task never settles, the machine is up regardless. */
    public function testAStartedMachineIsNoLongerStartingEvenWithItsTaskStillOpen(): void
    {
        $machine = $this->machine('running', ProxmoxAction::Start);

        self::assertFalse($machine->isStarting());
        self::assertFalse($machine->isTransitioning());
        self::assertTrue($machine->isRunning());
    }

    public function testAShutdownIsUnderWayWhileTheMachineStillAnswers(): void
    {
        $machine = $this->machine('running', ProxmoxAction::Shutdown);

        self::assertTrue($machine->isStopping());
        // Its owner cannot set a password on a machine the SSH session would be cut from halfway.
        self::assertFalse($machine->acceptsPassword());
    }

    public function testAStoppedMachineIsNoLongerStopping(): void
    {
        self::assertFalse($this->machine('stopped', ProxmoxAction::Shutdown)->isStopping());
        self::assertFalse($this->machine('stopped', ProxmoxAction::Stop)->isTransitioning());
    }

    /**
     * A host that cannot be reached says nothing about what its machines are doing. « État inconnu »
     * is the honest card, and it is not one that keeps the page reloading.
     */
    public function testAnUnreachableHostIsNotAMachineInTransition(): void
    {
        $machine = $this->machine(null, ProxmoxAction::Start);

        self::assertFalse($machine->isKnown());
        self::assertFalse($machine->isTransitioning());
    }

    /** A clone or a provisioning run is not this card's business, and never colours it. */
    public function testANonPowerActionNeverColoursTheCard(): void
    {
        self::assertFalse($this->machine('stopped', ProxmoxAction::Clone)->isTransitioning());
    }

    private function machine(?string $status, ?ProxmoxAction $pending = null): UserMachine
    {
        $host = new ProxmoxHost('campus', '192.0.2.10', 'svc');

        return new UserMachine(
            new GuestAccount($host, 'pve', 101, 'etudiant'),
            'poste-01',
            '192.0.2.101',
            $status,
            null,
            ['etudiant'],
            null,
            null,
            $pending,
        );
    }
}
