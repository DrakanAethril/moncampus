<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * The one question asked of a freshly opened session: does it actually run what it is given?
 *
 * Opening a session and running a command are not the same thing, and the difference is invisible
 * from the outside. Debian and Ubuntu cloud images ship with cloud-init's `disable_root` enabled,
 * which writes the SSH keys into **root's** authorized_keys behind a forced command:
 *
 *     no-port-forwarding,no-agent-forwarding,no-X11-forwarding,command="echo 'Please login as the
 *     user \"debian\" rather than the user \"root\".';echo;sleep 10;exit 142" ssh-ed25519 AAAA…
 *
 * The key is accepted, so the login succeeds and every check that only asks « did it connect »
 * says yes. But `command=` replaces whatever is asked for: the machine prints that sentence, sleeps
 * ten seconds and exits 142. Accounts are never created, nothing reports a failure, and each
 * attempt costs ten seconds - enough of them and the page dies on PHP's time limit rather than on
 * anything that names the cause.
 *
 * So the probe echoes a marker and insists on seeing it come back. A forced command cannot produce
 * it, whatever else it prints.
 */
final class GuestShellProbe
{
    /**
     * Unlikely in any greeting, and deliberately not a word: a machine's motd is arbitrary text,
     * and the marker has to be something no banner would say by accident.
     */
    public const string MARKER = '__moncampus_shell_ok__';

    /** Kept short enough that the whole sentence, prefix included, still fits one log entry. */
    private const int MAX_ANSWER = 160;

    /** The command to send. Quoted so no shell splits the marker or expands anything in it. */
    public static function command(): string
    {
        return "echo '".self::MARKER."'";
    }

    public static function provesCommandsRun(string $output): bool
    {
        return str_contains($output, self::MARKER);
    }

    /**
     * The machine's own answer, for the message an administrator reads - it is what names the fix,
     * far better than anything this application could say on its behalf.
     */
    public static function describe(string $output): string
    {
        $answer = trim($output);

        if ('' === $answer) {
            return 'it answered nothing at all';
        }

        return 'it answered: '.(self::MAX_ANSWER < \strlen($answer) ? substr($answer, 0, self::MAX_ANSWER - 1).'…' : $answer);
    }
}
