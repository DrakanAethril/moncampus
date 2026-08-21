<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The steps a machine's installation log can record, as a closed list.
 *
 * Codes rather than sentences: what is stored is what happened, and how it is worded belongs in the
 * translations with everything else this application says. It also means the wording can be fixed
 * afterwards without rewriting rows - a log is written once and read months later.
 *
 * The list is deliberately the chain the design fixes, in order, plus the ways each link can refuse:
 * clone → configure → start → answer → accounts → post-installation → shutdown.
 */
enum VmInstallStep: string
{
    /** An address was taken out of the range for this machine. */
    case AddressReserved = 'addressReserved';

    /** The range had nothing left to give. */
    case AddressUnavailable = 'addressUnavailable';

    case CloneRequested = 'cloneRequested';
    case CloneFinished = 'cloneFinished';
    case CloneFailed = 'cloneFailed';

    /** Name, address and keys written into the cloud-init drive. */
    case Configured = 'configured';

    /** The account MonCampus created for itself, and therefore the one it will log in with. */
    case AccountNamed = 'accountNamed';

    /** Which keys went in, named one by one - the answer to "why can I not log in". */
    case KeysInstalled = 'keysInstalled';

    case ConfigurationFailed = 'configurationFailed';
    case StartRequested = 'startRequested';

    /** The machine answered SSH: everything after this point is happening inside it. */
    case Reachable = 'reachable';

    /** It did not answer - with the reason, which is the whole point of recording it. */
    case Unreachable = 'unreachable';

    /**
     * A step that could not be taken *yet*, and why.
     *
     * Written only when the reason changes, so a machine polled for an hour carries one line rather
     * than seven hundred. It exists because the log used to stop at « clonage demandé » whatever the
     * cause - a hypervisor that had stopped answering, a provisioning account being refused - and
     * the reader had no way to tell a slow clone from a broken one.
     */
    case Waiting = 'waiting';

    case AccountsApplied = 'accountsApplied';
    case AccountsFailed = 'accountsFailed';

    /** The clock was pointed at the VLAN's gateway - with the address, which is what gets checked. */
    case TimeSyncConfigured = 'timeSyncConfigured';

    /**
     * It was not, and why. Not fatal on its own: a machine whose clock is wrong is still a machine
     * the students can use, and failing it would hold the whole class behind a template problem.
     * Red in the log rather than silent, which is the difference that matters.
     */
    case TimeSyncFailed = 'timeSyncFailed';
    case PostInstallRun = 'postInstallRun';
    case PostInstallFailed = 'postInstallFailed';

    /**
     * The machine was asked to shut down, once everything was installed on it.
     *
     * The last link of the chain, and the one that surprises: a machine is built switched off. It
     * is its user who starts it from « Mes machines virtuelles » - a class's worth of machines
     * running all night because somebody built them in the afternoon is the state this avoids.
     */
    case ShutdownRequested = 'shutdownRequested';

    /**
     * It was not - and the machine is left running rather than the batch failed. Everything that
     * was asked for is on it; being switched on is not a defect its user cannot fix in one click.
     */
    case ShutdownFailed = 'shutdownFailed';

    /** The label key the screen shows - the French wording lives in the translations. */
    public function labelKey(): string
    {
        return 'vmInstallStep'.ucfirst($this->value).'Label';
    }
}
