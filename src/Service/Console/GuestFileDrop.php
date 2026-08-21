<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestUnreachableException;

/**
 * Putting a file on a machine, and getting one back, over the session that is already open.
 *
 * **`base64` on the existing SSH session, and nothing else**: no port, no service, no second
 * authentication path, no SFTP subsystem to enable on the guests. The whole feature is two shell
 * commands, which is why it fits in a lot about saving time rather than in one about plumbing.
 *
 * The ceiling is **8 MiB**, and it is a real limit rather than a nervous one: base64 inflates by a
 * third, and the whole thing travels inside one SSH command line's stdin. Beyond that the honest
 * answer is a link the machine downloads for itself - a clipboard is not a file transfer service,
 * and pretending otherwise is how a console starts timing out on somebody's ISO.
 *
 * The destination is the **current working directory of the console's shell**, which is what makes
 * this feel like dropping a file where you are standing rather than uploading it somewhere. It is
 * read from the machine at the moment of the drop, so it follows whoever has been `cd`-ing around.
 */
class GuestFileDrop
{
    /** 8 MiB. See the class docblock: this is a ceiling, not a guess. */
    public const int MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Writes a file into the console's current directory and answers where it landed.
     *
     * @throws ConsoleFileTooLargeException when the payload is past the ceiling
     * @throws ConsoleFileRefusedException  when the machine would not write it - a full disk, a
     *                                      directory the console's account may not write into
     * @throws GuestUnreachableException
     */
    public function send(GuestShell $shell, string $name, string $contents): string
    {
        if (\strlen($contents) > self::MAX_BYTES) {
            throw new ConsoleFileTooLargeException('consoleFileTooLargeMessage');
        }

        $safe = self::safeName($name);
        $directory = $this->currentDirectory($shell);
        $path = rtrim($directory, '/').'/'.$safe;

        // Written through a heredoc-free pipeline: the payload is a single base64 token with no
        // shell metacharacter in it by construction, and `-d` refuses anything that is not one.
        $result = $shell->runAsSelf(\sprintf(
            'printf %%s %s | base64 -d > %s && echo OK',
            escapeshellarg(base64_encode($contents)),
            escapeshellarg($path),
        ));

        if (!str_contains($result->output, 'OK')) {
            throw new ConsoleFileRefusedException('consoleFileWriteFailedMessage');
        }

        return $path;
    }

    /**
     * Reads a file off the machine.
     *
     * The size is checked **on the machine, before reading**: pulling a two-gigabyte file into
     * PHP's memory to discover it is too big is the failure this ordering exists to avoid.
     *
     * @throws ConsoleFileTooLargeException
     * @throws ConsoleFileRefusedException
     * @throws GuestUnreachableException
     */
    public function fetch(GuestShell $shell, string $path): string
    {
        $quoted = escapeshellarg($path);
        $probe = trim($shell->runAsSelf(\sprintf('if [ -f %1$s ] && [ -r %1$s ]; then wc -c < %1$s; else echo ABSENT; fi', $quoted))->output);

        if ('' === $probe || str_contains($probe, 'ABSENT')) {
            throw new ConsoleFileRefusedException('consoleFileNotFoundMessage');
        }

        if ((int) $probe > self::MAX_BYTES) {
            throw new ConsoleFileTooLargeException('consoleFileTooLargeMessage');
        }

        $encoded = preg_replace('/\s+/', '', $shell->runAsSelf(\sprintf('base64 -w0 %s', $quoted))->output) ?? '';
        $contents = base64_decode($encoded, true);

        if (false === $contents) {
            throw new ConsoleFileRefusedException('consoleFileReadFailedMessage');
        }

        return $contents;
    }

    /**
     * Where the console's shell currently stands.
     *
     * Read from the process itself rather than from a `pwd` typed into the pane: typing into the
     * pane would put the command on the screen, and a file drop that leaves debris in somebody's
     * terminal is a file drop they stop using. Falls back to the account's home, which is where a
     * shell starts.
     */
    private function currentDirectory(GuestShell $shell): string
    {
        $pane = trim($shell->runAsSelf(\sprintf(
            'p=$(tmux display-message -p -t %s "#{pane_pid}" 2>/dev/null); '
            .'[ -n "$p" ] && readlink -f /proc/$(pgrep -P "$p" -n || echo "$p")/cwd || echo "$HOME"',
            TmuxCommandLine::SESSION,
        ))->output);

        return '' === $pane || !str_starts_with($pane, '/') ? '~' : $pane;
    }

    /**
     * The name as it will exist on the machine.
     *
     * A basename, and nothing that could climb out of the directory it is dropped into: the name
     * comes from an upload field or from a library row, and neither is a path.
     */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.@ -]+/u', '-', $name) ?? '';
        $name = preg_replace('/-{2,}/', '-', $name) ?? $name;
        $name = trim($name, '-. ');

        return '' === $name ? 'fichier' : mb_substr($name, 0, 120);
    }
}
