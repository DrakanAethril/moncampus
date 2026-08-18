<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Entity\ProxmoxHost;
use App\Entity\ProxmoxOperation;
use App\Entity\User;
use App\Enum\PlatformActivityType;
use App\Enum\ProxmoxAction;
use App\Service\PlatformActivityRecorder;
use App\Service\Proxmox\ProxmoxOperationTracker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs the post-installation script on one machine, and writes down everything about it.
 *
 * **The order is not negotiable**, and it is the only reason this is a service rather than a call:
 *
 *     clone → configure → start → reachable → accounts → post-installation
 *
 * A script that installs a package for a user who does not exist yet fails in a way nobody can
 * read. Running it before the accounts exist is the commonest way to make that happen, so the
 * runner is given the accounts as a token and the caller is expected to have created them.
 *
 * The tracing is deliberate and doubled. This field is arbitrary command execution as root - which
 * gives an administrator no power they do not already have, since they hold the platform key and
 * every password they just created, but which saves twenty-four manual logins. So it gets an
 * operation row *and* a platform-activity entry, and it is never exposed to a lower role.
 *
 * A clean disconnection is not a failure: a script that reboots cuts the session before the exit
 * status arrives. That settles as `unknown`, the output is kept, and the screen offers to re-check.
 */
class PostInstallRunner
{
    public function __construct(
        private readonly PostInstallScript $script,
        private readonly ProxmoxOperationTracker $tracker,
        private readonly PlatformActivityRecorder $activityRecorder,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $logins the accounts that already exist on the machine - substituted as
     *                             {{users}}, and the reason this runs after the accounts and not
     *                             before
     */
    public function run(
        GuestShell $shell,
        ProxmoxHost $host,
        string $node,
        int $vmid,
        string $guestName,
        string $ip,
        string $rawScript,
        array $logins,
        ?User $requestedBy,
        ?string $batchLabel = null,
    ): ProxmoxOperation {
        $operation = $this->tracker->begin($host, ProxmoxAction::PostInstall, $requestedBy, $node, $vmid, $guestName, 'qemu');

        $rendered = $this->script->wrap($this->script->render($rawScript, [
            'hostname' => $guestName,
            'ip' => $ip,
            'vmid' => (string) $vmid,
            'users' => implode(' ', $logins),
            'batch' => $batchLabel ?? '',
        ]));

        try {
            // Written to a file and executed, rather than piped: a heredoc through exec() mangles
            // quoting in ways that only show up on the one script that uses a nested quote.
            $remotePath = \sprintf('/tmp/moncampus-postinstall-%d.sh', $vmid);
            // A quoted heredoc delimiter, so the shell that receives this expands nothing: the
            // script's own $variables and backticks are the script's business, not ours.
            $shell->run(\sprintf("cat > %s <<'MONCAMPUS_EOF'\n%s\nMONCAMPUS_EOF", $remotePath, $rendered));
            $shell->run(\sprintf('chmod +x %s', $remotePath));

            $result = $shell->run(\sprintf('%s; rm -f %s', $remotePath, $remotePath));
        } catch (GuestUnreachableException $exception) {
            $this->tracker->failed($operation, $exception->getMessage());

            throw $exception;
        }

        $operation->setOutput($this->script->truncate($result->output));
        $operation->setExitCode($result->exitCode);

        if ($result->isUndetermined()) {
            // The session ended before a verdict came back - a script that reboots does exactly
            // this. Claiming either outcome would be a lie.
            $operation->markUnknown('The session ended before the script reported how it went - a reboot, most likely.');
        } elseif ($result->isSuccess()) {
            $operation->markSucceeded();
        } else {
            $operation->markFailed(\sprintf('The script exited with code %d.', $result->exitCode ?? -1));
        }

        $this->entityManager->flush();

        $this->activityRecorder->record(PlatformActivityType::ProxmoxPostInstallRun, $requestedBy, null, [
            'host' => $host->getLabel(),
            'guest' => $guestName,
            'vmid' => (string) $vmid,
        ]);

        return $operation;
    }
}
