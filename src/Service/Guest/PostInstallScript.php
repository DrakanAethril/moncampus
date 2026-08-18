<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * The post-installation script: what its tokens mean, what guards it runs under, and what happens
 * to its output.
 *
 * Pure - text in, text out - because all three of those are decisions worth pinning and none of
 * them needs a machine.
 *
 * The field this serves is arbitrary command execution as root, and that is a considered choice
 * rather than an oversight: it **gives an administrator no power they do not already have** (they
 * hold the platform key, the Proxmox credentials and every password they just created), and it
 * saves twenty-four manual logins. It is traced anyway - an operation row and a platform-activity
 * entry - and it is never exposed to a lower role.
 *
 * One rule has teeth: **an unknown token is left exactly as written**, never replaced by nothing. A
 * script that writes `{{hostnmae}}` into a file has a typo somebody can see; one that writes an
 * empty string has a bug that surfaces three weeks later as a machine with a blank MOTD.
 */
class PostInstallScript
{
    /** Exactly what the field's help text promises, and the order it lists them in. */
    public const array TOKENS = ['hostname', 'ip', 'vmid', 'users', 'batch'];

    /** Enough to read an apt failure; not so much that the column becomes a place scripts live. */
    public const int MAX_OUTPUT_BYTES = 65536;

    /**
     * @param array<string, string> $tokens
     */
    public function render(string $script, array $tokens): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static fn (array $match): string => \array_key_exists($match[1], $tokens) ? $tokens[$match[1]] : $match[0],
            $script,
        );
    }

    /**
     * Wraps the script in the guards that stop it hanging instead of failing.
     *
     * `set -e` so a failed step ends the run rather than letting the next twelve pile errors on top
     * of it; `exec </dev/null` and `DEBIAN_FRONTEND=noninteractive` because a command that stops to
     * ask a question waits for an answer nobody is there to give - and waits until the five-minute
     * ceiling, which is a long time to be told nothing.
     */
    public function wrap(string $script): string
    {
        $body = ltrim($script);

        // Somebody who wrote their own shebang meant it; two of them is a syntax error.
        if (str_starts_with($body, '#!')) {
            $lines = explode("\n", $body, 2);

            return $lines[0]."\nset -e\nexport DEBIAN_FRONTEND=noninteractive\nexec </dev/null\n".($lines[1] ?? '');
        }

        return "#!/bin/bash\nset -e\nexport DEBIAN_FRONTEND=noninteractive\nexec </dev/null\n".$body;
    }

    /**
     * Caps the output on a line boundary.
     *
     * The boundary matters: what gets cut is usually somebody's apt output, and half a line reads
     * as corruption rather than as truncation.
     */
    public function truncate(string $output): string
    {
        if (\strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        $cut = substr($output, 0, self::MAX_OUTPUT_BYTES);
        $lastBreak = strrpos($cut, "\n");

        // The line terminator is kept, so what survives is whole lines and nothing else - a body
        // ending mid-line is what makes truncation look like corruption.
        //
        // No line break at all in 64 KiB - one enormous line - leaves no boundary to respect, and
        // the raw cut is then the only option.
        $body = false !== $lastBreak ? substr($cut, 0, $lastBreak + 1) : $cut."\n";

        return $body."…\n";
    }
}
