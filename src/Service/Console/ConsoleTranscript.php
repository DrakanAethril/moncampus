<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * What a console session leaves behind.
 *
 * **The panel is recorded, never the keystrokes**, and the difference is not cosmetic: a password
 * typed at a `sudo` or `passwd` prompt does not appear on the screen - the terminal is hiding it -
 * so it is exactly what a recording of the screen does not capture. Recording keystrokes would
 * capture it. The rule is: *what somebody standing behind the shoulder would have seen.*
 *
 * From which the whole mechanism follows. A line enters the transcript **only once it has scrolled
 * off the top**, that is, once it can no longer change; everything still on screen is still moving,
 * and copying it at every exchange would write down each intermediate state of the line being
 * typed. So the transcript is always *what has scrolled past* followed by *the screen as it is*,
 * and `stableLength` is the boundary between the two - one integer rather than a second copy of the
 * screen kept somewhere.
 *
 * Pure: it takes strings and answers strings. Nothing here reads a request, a machine or a row.
 */
class ConsoleTranscript
{
    /** 256 KiB. Past that, a transcript is a log file, and this is not where log files live. */
    public const int MAX_BYTES = 262144;

    /** French, because it is read inside the transcript by whoever opens it. */
    public const string TRUNCATION_MARK = "… [début de la transcription tronqué]\n";

    /**
     * Folds one screen into the transcript.
     *
     * @param string $transcript   what is recorded so far
     * @param int    $stableLength how much of it has scrolled off and can no longer change
     * @param bool   $wasTruncated whether the beginning has already been cut - a transcript that
     *                             has been cut stays cut, so the flag only ever goes one way
     */
    public function record(string $transcript, int $stableLength, ConsolePane $pane, bool $wasTruncated = false): ConsoleTranscriptState
    {
        // A full-screen program redraws its whole screen at every refresh, so every exchange would
        // look like « nothing in common with the previous one » and twenty screens of `top` would
        // be written down. A session inside `vim` is silent for as long as it stays there - which
        // is also the honest reading of « what somebody behind the shoulder would have seen »: one
        // screen, not twenty.
        if ($pane->alternate) {
            return new ConsoleTranscriptState($transcript, $stableLength, $wasTruncated);
        }

        $stable = substr($transcript, 0, $stableLength);
        $previous = substr($transcript, $stableLength);
        $current = $pane->plainText();

        $scrolled = $this->scrolledOff($previous, $current);
        $stable .= '' === $scrolled ? '' : $scrolled."\n";

        return $this->cap($stable.$current, \strlen($stable), $wasTruncated);
    }

    /**
     * The lines of the previous screen that the new one has pushed off the top.
     *
     * Found by sliding the old screen up against the new one until they agree: the largest k such
     * that the old screen minus its first k lines is a prefix of the new one. k = 0 means nothing
     * moved, k = every line means the screen was cleared or replaced wholesale - and in that case
     * the whole of the previous screen is final, which is right.
     */
    private function scrolledOff(string $previous, string $current): string
    {
        if ('' === $previous) {
            return '';
        }

        $old = explode("\n", $previous);
        $new = explode("\n", $current);
        $count = \count($old);

        for ($k = 0; $k < $count; ++$k) {
            if ($this->fits(\array_slice($old, $k), $new)) {
                return implode("\n", \array_slice($old, 0, $k));
            }
        }

        return $previous;
    }

    /**
     * Does what is left of the old screen still sit at the top of the new one?
     *
     * Every line but the last has to match exactly. **The last one only has to be a prefix of the
     * other, either way round** - and that single relaxation is what stops a transcript recording
     * one line per keystroke: the bottom line is the one being typed, so `ls` following `l` is the
     * same line growing, not a new one, and a backspace is the same line shrinking.
     *
     * @param list<string> $kept
     * @param list<string> $new
     */
    private function fits(array $kept, array $new): bool
    {
        $length = \count($kept);

        if (0 === $length) {
            // Nothing of the old screen is left: it was cleared, or replaced wholesale. Everything
            // that was on it is final, which is what the caller does with an empty answer here.
            return true;
        }

        if (\count($new) < $length) {
            return false;
        }

        for ($i = 0; $i < $length - 1; ++$i) {
            if ($kept[$i] !== $new[$i]) {
                return false;
            }
        }

        $last = $kept[$length - 1];
        $facing = $new[$length - 1];

        return str_starts_with($facing, $last) || str_starts_with($last, $facing);
    }

    /**
     * Cuts the beginning when the ceiling is reached, and says so inside the text.
     *
     * The beginning rather than the end, because what has just happened is what somebody opening a
     * transcript is looking for. The mark is part of the text: a screen that lies about what it is
     * showing is worse than a screen that shows less.
     */
    private function cap(string $text, int $stableLength, bool $wasTruncated): ConsoleTranscriptState
    {
        if (\strlen($text) <= self::MAX_BYTES) {
            return new ConsoleTranscriptState($text, $stableLength, $wasTruncated);
        }

        $keep = self::MAX_BYTES - \strlen(self::TRUNCATION_MARK);
        $cut = substr($text, -$keep);
        // Cut on a line boundary when there is one nearby, so the transcript does not open on half
        // a word - and never at the cost of keeping less than the ceiling allows.
        $break = strpos($cut, "\n");
        $cut = false !== $break && $break < 512 ? substr($cut, $break + 1) : $cut;

        $capped = self::TRUNCATION_MARK.$cut;
        $removed = \strlen($text) - \strlen($cut);

        return new ConsoleTranscriptState($capped, max(0, $stableLength - $removed + \strlen(self::TRUNCATION_MARK)), true);
    }
}
