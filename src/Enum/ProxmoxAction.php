<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What MonCampus asked a hypervisor to do. One case per thing the console can request, and the
 * list is deliberately closed.
 *
 * **There is no `delete`, and there must never be one.** The application stops machines; it does
 * not destroy them. The absence of a case here is part of how that holds: an action that cannot be
 * named cannot be logged, and one that cannot be logged has no business being performed.
 *
 * Shutdown and Stop are two cases rather than one with a flag, for the same reason the screen
 * draws two buttons: a polite ACPI request and a power cut are not variants of each other, and
 * nobody should pick the second by accident.
 */
enum ProxmoxAction: string
{
    /** POST …/status/start */
    case Start = 'start';

    /** POST …/status/shutdown - ACPI, the guest gets to close its files. */
    case Shutdown = 'shutdown';

    /** POST …/status/stop - the power cut. */
    case Stop = 'stop';

    /** POST …/status/reboot */
    case Reboot = 'reboot';

    /** POST …/qemu/{id}/clone - from a template, in the creation wizard. */
    case Clone = 'clone';

    /** POST …/qemu or …/lxc - a machine built from scratch, from an ISO. */
    case Create = 'create';

    /** The guest accounts of a machine were brought in line with what was asked for (batch 4). */
    case Provision = 'provision';

    /** A post-installation script was run over SSH (batch 4). */
    case PostInstall = 'postinstall';

    public function labelKey(): string
    {
        return match ($this) {
            self::Start => 'proxmoxActionStartLabel',
            self::Shutdown => 'proxmoxActionShutdownLabel',
            self::Stop => 'proxmoxActionStopLabel',
            self::Reboot => 'proxmoxActionRebootLabel',
            self::Clone => 'proxmoxActionCloneLabel',
            self::Create => 'proxmoxActionCreateLabel',
            self::Provision => 'proxmoxActionProvisionLabel',
            self::PostInstall => 'proxmoxActionPostInstallLabel',
        };
    }

    /** The four that the machines list offers on a row, and the only ones a POST from it may name. */
    public function isPowerAction(): bool
    {
        return \in_array($this, [self::Start, self::Shutdown, self::Stop, self::Reboot], true);
    }

    /**
     * Which of the host's `allow*` switches has to be on. Starting and rebooting are the same
     * permission - a reboot is a stop the machine comes back from - while shutdown and stop share
     * the stopping one.
     */
    public function requiredPermission(): string
    {
        return match ($this) {
            self::Start, self::Reboot => 'start',
            self::Shutdown, self::Stop => 'stop',
            self::Clone, self::Create => 'create',
            // Guest accounts and post-installation touch the inside of a machine, not the
            // hypervisor: no Proxmox permission gates them, only the host being in scope.
            self::Provision, self::PostInstall => 'none',
        };
    }

    /**
     * Which of the host's two service accounts issues this action - and therefore **owns the task**
     * Proxmox opens for it.
     *
     * Not a permission rule: it does not say who may do the thing, it says who did it. Proxmox lets
     * an account read the status of its *own* tasks with no privilege at all, and answers HTTP 403
     * `(/nodes/<node>, Sys.Audit)` for anyone else's. So asking the everyday account how a creation
     * went would force `Sys.Audit` onto the very credentials this design keeps as small as it can -
     * which is what reached production on the first batch deployed there.
     *
     * Provision and PostInstall never touch the Proxmox API - they act inside a machine over SSH -
     * so they open no task and could answer either way. They answer "everyday" because that is the
     * account a host is guaranteed to have: provision() throws on a host that carries no second
     * credential set, and an action that opens no task must not be able to fail for the want of
     * credentials it does not use.
     *
     * The match is exhaustive on purpose: a new action must state which account issues it rather
     * than inherit an answer from a default arm.
     */
    public function usesProvisioningAccount(): bool
    {
        return match ($this) {
            self::Clone, self::Create => true,
            self::Start, self::Shutdown, self::Stop, self::Reboot, self::Provision, self::PostInstall => false,
        };
    }

    /**
     * How long a task of this kind may legitimately still be running before MonCampus stops
     * believing it, in seconds.
     *
     * **A ceiling per action, because one number cannot serve both ends of this list.** A start
     * that has not finished in five minutes is a start that will not finish; a full clone of a
     * hundred-gigabyte template onto a busy datastore takes as long as the copy takes, and several
     * of them run at once when a class is deployed. The single five-minute ceiling that used to
     * cover both is what declared perfectly healthy clones `unknown` - and an `unknown` creation
     * fails its machine, which is then never configured and never started: the machine exists on
     * the hypervisor as a bare copy of the template, with the template's address and no account.
     *
     * The number is not a timeout on anything - nothing is cancelled and nothing is retried. It
     * only says when a row nobody can reach a verdict on stops being followed.
     */
    public function maxTaskDurationSeconds(): int
    {
        return match ($this) {
            // The copy itself. Bounded only so a row cannot poll for ever.
            self::Clone, self::Create => 3600,
            // Power actions answer in seconds; one that has not in five minutes is stuck.
            self::Start, self::Shutdown, self::Stop, self::Reboot => 300,
            // These open no Proxmox task at all - they act inside the machine over SSH - so the
            // value is only ever used to close a row that was written and never settled.
            self::Provision, self::PostInstall => 300,
        };
    }

    /** The badge colour the journal gives it - power actions read as neutral, creation as notable. */
    public function badgeModifier(): string
    {
        return match ($this) {
            self::Start, self::Shutdown, self::Stop, self::Reboot => 'gray',
            self::Clone, self::Create => 'blue',
            self::Provision => 'teal',
            self::PostInstall => 'purple',
        };
    }
}
