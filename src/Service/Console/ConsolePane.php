<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * One photograph of a machine's screen, as it came back from `tmux`.
 *
 * Immutable, and deliberately dumb: the pane is arbitrary bytes carrying the escape sequences the
 * programs inside the machine chose, and nothing here interprets them. xterm.js is the terminal;
 * this is the envelope that got the bytes to it, plus the three things that are *not* in the pane
 * text and that a terminal is unusable without - where the cursor is, how big the window is, and
 * whether a full-screen program (`vim`, `top`) has taken over the alternate screen.
 *
 * `digest` is what the next exchange waits on. It is computed inside the machine over exactly the
 * bytes below, so the browser can hand it back and the far side can answer « nothing has changed »
 * without sending the screen again.
 */
final class ConsolePane
{
    public function __construct(
        public readonly string $digest,
        public readonly string $content,
        public readonly int $cursorX,
        public readonly int $cursorY,
        public readonly int $columns,
        public readonly int $rows,
        public readonly bool $alternate,
    ) {
    }

    /**
     * Reads what the exchange printed: a digest, a line of measurements, then the screen verbatim.
     *
     * Split twice and no further. The pane may contain anything at all, newlines and escape
     * sequences included, so it is taken as the remainder rather than parsed - a screen that is
     * re-parsed is a screen that eventually gets corrupted by its own contents.
     *
     * @throws ConsoleNotReadyException when the machine answered something that is not a screen,
     *                                  which is what a missing or dead tmux looks like from here
     */
    public static function parse(string $output): self
    {
        $parts = explode("\n", $output, 3);

        if (3 !== \count($parts) || 1 !== preg_match('/^[0-9a-f]{32}$/', $parts[0])) {
            throw new ConsoleNotReadyException('The machine did not answer with a screen.');
        }

        $measures = explode(' ', trim($parts[1]));

        if (5 !== \count($measures)) {
            throw new ConsoleNotReadyException('The machine answered a screen without its measurements.');
        }

        return new self(
            $parts[0],
            $parts[2],
            (int) $measures[0],
            (int) $measures[1],
            (int) $measures[2],
            (int) $measures[3],
            '1' === $measures[4],
        );
    }

    /**
     * What gets appended to a transcript: the screen with its colours stripped and its trailing
     * blank lines dropped.
     *
     * **The panel is what is recorded, never the keystrokes** - a password typed at a `sudo` or
     * `passwd` prompt does not appear on the screen, because the terminal is hiding it, so it is
     * exactly what a recording of the screen does not capture. Recording keystrokes would capture
     * it. The rule is: what somebody standing behind the shoulder would have seen.
     */
    public function plainText(): string
    {
        // CSI/OSC sequences out. The pane arrives with them because the same bytes are what the
        // browser renders; a transcript is read by a person, in a text field.
        $plain = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-9;?]*[ -\/]*[@-~]|\x1b[@-Z\\\\-_]/', '', $this->content) ?? $this->content;

        return rtrim($plain);
    }
}
