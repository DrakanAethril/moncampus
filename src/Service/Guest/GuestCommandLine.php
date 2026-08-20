<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * Turns what a caller wants run into the line actually sent down the SSH session.
 *
 * Two things are added to every command, and both exist because of a way an unattended command
 * turns into a hang rather than a failure: stdin comes from /dev/null so nothing can stop to ask a
 * question, and `DEBIAN_FRONTEND=noninteractive` so a package script does not draw a dialog. stderr
 * is folded into the output because the output is what gets recorded, and a diagnostic that went to
 * stderr is exactly the one worth keeping.
 *
 * **Elevation is decided here and nowhere else.** Everything this application runs inside a guest is
 * administrative, so the question is never which commands to elevate but only whether elevation is
 * needed at all: as root it is not, as anybody else it always is. Deciding per call site would mean
 * a command someone forgets - and a forgotten one does not fail loudly, it returns "permission
 * denied" into an output nobody reads while the account it should have created never appears.
 *
 * `sudo -n` rather than `sudo`: a machine whose sudoers asks for a password must fail immediately
 * and say so, not wait for one that is never coming.
 */
final class GuestCommandLine
{
    public static function build(string $command, string $username): string
    {
        $inner = \sprintf('DEBIAN_FRONTEND=noninteractive %s', $command);

        // Handed to a shell rather than to sudo directly: these commands carry loops, pipes,
        // redirections and a heredoc holding an entire script, none of which sudo would interpret.
        $line = 'root' === $username
            ? $inner
            : \sprintf('sudo -n /bin/sh -c %s', escapeshellarg($inner));

        return $line.' < /dev/null 2>&1';
    }
}
