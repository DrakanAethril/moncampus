<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * The commands that have already been run on a machine, read back out of the transcripts.
 *
 * The third source of the palette, and the one that costs nothing to keep: the transcripts are
 * already there, and what they hold is the screen - so a prompt line *is* a command somebody typed,
 * echoed by the shell. Nothing extra is recorded to get this, which matters given that the one
 * thing this feature must never do is record keystrokes.
 *
 * Pure, and matched loosely on purpose. A prompt is whatever the machine's shell decided it is:
 * `moncampus@tp-sisr-03:~$ `, `root@debian:/etc#`, a coloured one whose escapes have already been
 * stripped by ConsolePane::plainText(). What is common to all of them is `something@something`,
 * then anything, then `$` or `#`, then the command. A line that does not look like that is output,
 * and output is not offered as a command to run again.
 */
final class ConsoleHistory
{
    /** Enough to cover a session; past that the palette is a scrolling list nobody reads. */
    public const int MAX_ENTRIES = 40;

    /**
     * @param list<string> $transcripts newest last
     *
     * @return list<string> the commands, most recent first, without repeats
     */
    public static function extract(array $transcripts): array
    {
        /** @var array<array-key, string> $found */
        $found = [];

        foreach ($transcripts as $transcript) {
            preg_match_all('/^[\w.-]+@[\w.-]+:[^\n]*?[$#]\s+(\S[^\n]*)$/m', $transcript, $matches);

            foreach ($matches[1] as $command) {
                $command = trim($command);

                if ('' !== $command) {
                    // Keyed by the command so that running `df -h` eight times is one entry, dated
                    // by its last run - **and kept as the value too**, because PHP renormalises a
                    // numeric-string key into an int. A command that is all digits (a timestamp
                    // echoed back, a `205`) would otherwise come out of array_keys() as an integer
                    // and reach the palette as one, which is a TypeError three calls later.
                    unset($found[$command]);
                    $found[$command] = $command;
                }
            }
        }

        return array_values(\array_slice(array_reverse($found), 0, self::MAX_ENTRIES));
    }
}
