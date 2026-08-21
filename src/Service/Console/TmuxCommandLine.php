<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * Every `tmux` line the console ever sends, written in one place and nowhere else.
 *
 * **The pseudo-terminal lives inside the machine.** MonCampus holds no session, no socket and no
 * daemon: each exchange is an ordinary HTTP request that opens SSH, pushes the bytes somebody
 * typed into the machine's own `tmux`, photographs the screen, and hangs up. What survives between
 * two keystrokes - the live shell, the current directory, the open `vim` - survives because `tmux`
 * is holding it, on the far side.
 *
 * That is why this class is pure and why it was written first: it is the entire protocol, and the
 * only part of the console that can be judged without a virtual machine.
 *
 * Three properties it exists to guarantee:
 *
 *   - **Keystrokes travel as bytes, never as key names.** `send-keys -H` takes hexadecimal, so
 *     whatever xterm.js produced goes through untranslated. There is no key table to write, and
 *     therefore no key anybody forgot: `Ctrl-C`, `Tab`, `F7`, an accented character and a paste all
 *     take the same path.
 *   - **Nothing the browser says is ever spliced into a tmux line as text.** The bytes are
 *     validated as bytes and the digest as a digest; a value that is not one is refused rather than
 *     escaped, because there is no legitimate keystroke that needs a semicolon in that field.
 *   - **The screen is watched inside the machine.** The waiting loop runs on the far side at
 *     150 ms, so a command's output appears about that fast; the request answers the moment the
 *     screen changes. Polling from here would be an SSH handshake every 150 ms.
 *
 * `tmux >= 3.1` is required, for `send-keys -H`. Debian 11 ships 3.1c, Debian 12 ships 3.3a and
 * Ubuntu 22.04 ships 3.2a, so every template of the school qualifies - and machines the platform
 * installs get it during provisioning (App\Service\VmBatch\VmBatchExecutor), while older ones
 * install it by themselves at the first opening.
 */
final class TmuxCommandLine
{
    /**
     * One session per machine, named rather than numbered, and shared by whoever opens it.
     *
     * Two people on the same session see the same screen - which is not a conflict to resolve but
     * remote assistance falling out for free, and the reason the status bar says who else is there.
     */
    public const string SESSION = 'moncampus';

    /** How often the machine looks at its own screen while an exchange is waiting, in seconds. */
    public const float TICK_SECONDS = 0.15;

    public const int MIN_COLUMNS = 20;
    public const int MAX_COLUMNS = 500;
    public const int MIN_ROWS = 5;
    public const int MAX_ROWS = 200;

    /** What `Ctrl+F` may read back, and what a single command may be asked to return. */
    public const int MAX_HISTORY_LINES = 5000;

    /**
     * `screen-256color` rather than `tmux-256color`: the latter's terminfo entry is missing from a
     * minimal Debian install, and a pane whose TERM does not resolve draws nothing useful at all.
     */
    private const string TERMINAL = 'screen-256color';

    /** Is tmux there at all? Answered as one word, so the caller reads a state and not an exit code. */
    public static function presence(): string
    {
        return 'command -v tmux >/dev/null 2>&1 && printf ready || printf missing';
    }

    /**
     * Puts tmux on a machine that has none.
     *
     * Runs elevated, through the ordinary command path - so `DEBIAN_FRONTEND` and the closed stdin
     * of App\Service\Guest\GuestCommandLine apply, and a package script cannot draw a dialog nobody
     * is there to answer. `update` is tolerated failing: a machine whose lists are merely stale
     * still installs.
     */
    public static function install(): string
    {
        return 'apt-get update -qq || true; apt-get install -y tmux';
    }

    /**
     * Attaches to the machine's console session, or creates it.
     *
     * `-A` is the whole of « reprise » - closing the browser tab stops nothing, and coming back
     * lands in the shell that was already there.
     */
    public static function open(int $columns, int $rows): string
    {
        return implode("\n", [
            // Before the session exists, so the shell inside it is born knowing it has colours.
            \sprintf('tmux set-option -g default-terminal %s >/dev/null 2>&1 || true', escapeshellarg(self::TERMINAL)),
            self::newSession($columns, $rows),
            self::resizeWindow($columns, $rows),
        ]);
    }

    /**
     * One exchange: the bytes typed go in, the screen comes back - and the request waits inside the
     * machine until the screen actually changes.
     *
     * `$since` is the digest of the pane the browser is already showing. Comparing digests rather
     * than screens is what makes the wait correct across two requests: anything that happened while
     * nothing was listening still differs from what the browser has, so nothing is ever missed.
     *
     * @param string $hex        the bytes xterm.js produced, as hexadecimal; empty when the browser
     *                           is only watching
     * @param string $since      32 hexadecimal characters, or empty on the first exchange
     * @param float  $maxSeconds how long the machine may watch before answering anyway
     *
     * @throws \InvalidArgumentException when the bytes are not bytes or the digest is not a digest
     */
    public static function exchange(string $hex, string $since, int $columns, int $rows, float $maxSeconds): string
    {
        $keys = self::hexBytes($hex);
        $digest = self::digest($since);
        $ticks = max(1, (int) ($maxSeconds / self::TICK_SECONDS));

        $lines = [
            // Repeated on every exchange rather than only at opening: a tmux server that died
            // between two requests would otherwise turn every later keystroke into an error, and
            // recreating costs one socket round trip inside the machine.
            self::newSession($columns, $rows),
            self::resizeWindow($columns, $rows),
        ];

        if ('' !== $keys) {
            $lines[] = \sprintf('tmux send-keys -t %s -H %s', self::SESSION, $keys);
        }

        $lines[] = \sprintf('since=%s', escapeshellarg($digest));
        $lines[] = 'i=0';
        $lines[] = 'while :; do';
        $lines[] = '  '.self::snapshot();
        $lines[] = '  if [ "$d" != "$since" ]; then break; fi';
        $lines[] = \sprintf('  i=$((i+1)); if [ "$i" -ge %d ]; then break; fi', $ticks);
        $lines[] = \sprintf('  sleep %s', self::TICK_SECONDS);
        $lines[] = 'done';
        // The digest first so the reader can split on the first newline and keep the rest verbatim -
        // a pane is arbitrary bytes and must not be parsed twice.
        $lines[] = 'printf \'%s\n%s\' "$d" "$s"';

        return implode("\n", $lines);
    }

    /**
     * The screen as it is, with no waiting at all - the console wall's tile, and the reconnection
     * after an aborted request.
     */
    public static function snapshotOnly(int $columns, int $rows): string
    {
        return implode("\n", [
            self::newSession($columns, $rows),
            self::snapshot(),
            'printf \'%s\n%s\' "$d" "$s"',
        ]);
    }

    /**
     * The scrollback, plain, for searching through what has already scrolled past.
     *
     * Without `-e`: this is read as text, and escape sequences in a search would match nothing a
     * human ever typed.
     */
    public static function history(int $lines): string
    {
        $lines = max(1, min($lines, self::MAX_HISTORY_LINES));

        return \sprintf('tmux capture-pane -p -t %s -S -%d', self::SESSION, $lines);
    }

    /**
     * A whole command line, typed and run.
     *
     * Not a keystroke sequence: the palette, « devenir », the file drop and the broadcast all send
     * *a line*, and its text is somebody's command. It travels as its bytes for the same reason
     * keystrokes do - so nothing inside it can be read as tmux syntax. A `;` in a command is a `;`
     * in the shell, never the end of a tmux command.
     */
    public static function sendLine(string $command): string
    {
        // Stripped rather than escaped: a control character in a « line » is not part of a line,
        // and a newline in it would run whatever follows as a second command nobody typed.
        $clean = preg_replace('/[\x00-\x1f\x7f]/', '', $command) ?? '';

        return \sprintf('tmux send-keys -t %s -H %s 0d', self::SESSION, self::hexBytes(bin2hex($clean)));
    }

    public static function clampColumns(int $columns): int
    {
        return max(self::MIN_COLUMNS, min($columns, self::MAX_COLUMNS));
    }

    public static function clampRows(int $rows): int
    {
        return max(self::MIN_ROWS, min($rows, self::MAX_ROWS));
    }

    private static function newSession(int $columns, int $rows): string
    {
        return \sprintf(
            'tmux new-session -A -d -s %s -x %d -y %d >/dev/null 2>&1',
            self::SESSION,
            self::clampColumns($columns),
            self::clampRows($rows),
        );
    }

    /**
     * The window follows the box the browser measured, every time.
     *
     * A tmux window wider than the box it is drawn in is the classic « the lines break in the wrong
     * place » bug, and it cannot happen if the measurement travels with the keystrokes. Tolerated
     * failing: a window resized while nothing is attached answers an error on some versions, and it
     * is not worth an exchange.
     */
    private static function resizeWindow(int $columns, int $rows): string
    {
        return \sprintf(
            'tmux resize-window -t %s -x %d -y %d >/dev/null 2>&1 || true',
            self::SESSION,
            self::clampColumns($columns),
            self::clampRows($rows),
        );
    }

    /**
     * Photographs the screen into `$s`, and its digest into `$d`.
     *
     * The cursor is on the first line because it is not in the pane text and a terminal without one
     * is a terminal nobody trusts; it is part of what gets hashed, so moving the cursor alone is a
     * change the browser is told about.
     *
     * The digest is computed *there*: the loop compares it without ever sending the screen back,
     * which is what keeps a console at rest costing one small answer every eight seconds.
     */
    private static function snapshot(): string
    {
        return implode("\n  ", [
            \sprintf("m=$(tmux display-message -p -t %s '#{cursor_x} #{cursor_y} #{pane_width} #{pane_height} #{alternate_on}' 2>/dev/null)", self::SESSION),
            \sprintf('p=$(tmux capture-pane -p -e -t %s 2>/dev/null)', self::SESSION),
            's="$m',
            '$p"',
            'd=$(printf \'%s\' "$s" | md5sum | cut -c1-32)',
        ]);
    }

    /**
     * The bytes, one space-separated pair per byte, or a refusal.
     *
     * @throws \InvalidArgumentException
     */
    private static function hexBytes(string $hex): string
    {
        $hex = strtolower(trim($hex));

        if ('' === $hex) {
            return '';
        }

        if (0 !== \strlen($hex) % 2 || 1 !== preg_match('/^[0-9a-f]+$/', $hex)) {
            throw new \InvalidArgumentException('Keystrokes must be an even-length string of hexadecimal digits.');
        }

        return implode(' ', str_split($hex, 2));
    }

    /** @throws \InvalidArgumentException */
    private static function digest(string $digest): string
    {
        $digest = strtolower(trim($digest));

        if ('' === $digest) {
            return '';
        }

        if (1 !== preg_match('/^[0-9a-f]{32}$/', $digest)) {
            throw new \InvalidArgumentException('A screen digest is 32 hexadecimal characters.');
        }

        return $digest;
    }
}
