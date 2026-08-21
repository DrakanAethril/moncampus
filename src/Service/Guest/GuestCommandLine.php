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
 * **The variable is exported as a statement of its own, and the command always goes through
 * `/bin/sh -c`.** A `VAR=value cmd` prefix is only valid in front of a *simple* command: written in
 * front of a `for`, an `if` or a `while`, the shell answers `Syntax error: "do" unexpected` and the
 * command never runs at all. That is not a theoretical shape - the account probe is a `for` loop,
 * and with the prefix in front of it every machine answered "none of these accounts exist", which
 * read on screen as every declared account being permanently « à créer ». The failure is silent
 * twice over: the loop's own exit status is meaningless (it is the last iteration's), and a probe
 * that finds nothing looks exactly like a machine that has nothing.
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
        $inner = \sprintf('export DEBIAN_FRONTEND=noninteractive; %s', $command);

        // Handed to a shell rather than to sudo directly: these commands carry loops, pipes,
        // redirections and a heredoc holding an entire script, none of which sudo would interpret.
        // Root goes through the same shell rather than straight down the session, so that one form
        // is parsed one way - the root path used to be the raw string, which put the assignment
        // prefix back in front of whatever the caller wrote.
        $line = 'root' === $username
            ? \sprintf('/bin/sh -c %s', escapeshellarg($inner))
            : \sprintf('sudo -n /bin/sh -c %s', escapeshellarg($inner));

        return $line.' < /dev/null 2>&1';
    }

    /**
     * The same command, run as **the account the session logged in with**, with nothing added.
     *
     * The console is the one thing in this application that is not administrative by nature. It
     * opens on `moncampus` - the only account whose credentials the platform holds - and that
     * account elevates with `sudo` when the person typing decides to, exactly as they would in any
     * terminal. Sending its `tmux` down build() would put the shell inside a root session, and the
     * prompt would then say `root@` while the design says `moncampus@`.
     *
     * stderr is deliberately **not** folded in either: what comes back here is a screen, and a
     * diagnostic printed into the middle of it would corrupt the very bytes the browser renders.
     * The closed stdin stays, for the same reason it exists on build() - nothing may stop to ask.
     */
    public static function buildAsSelf(string $command): string
    {
        return \sprintf('/bin/sh -c %s < /dev/null', escapeshellarg($command));
    }
}
