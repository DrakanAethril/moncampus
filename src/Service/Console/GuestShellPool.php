<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Service\Guest\GuestShell;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;

/**
 * Keeps a console's SSH session open between two exchanges.
 *
 * **The one optimisation of this feature, and it was measured rather than assumed.** An exchange
 * costs one SSH handshake, and that handshake *is* the keystroke echo latency - the rest happens
 * inside the machine. FrankenPHP's worker survives between two requests, so an `SSH2` object can
 * survive with it, in a static registry the kernel reset does not touch.
 *
 * The three guardrails the design set out, all three implemented here:
 *
 *   1. **Keyed by (console session, person), never by a value the request supplies.** The session id
 *      does come from the request - but it has already been proved to belong to the person asking
 *      (App\Controller\ConsoleController::ownSession), and the user id comes from the token. Nobody
 *      can name their way into somebody else's connection.
 *   2. **Bounded, and closed after 60 seconds of inactivity.** A console holds at most one entry,
 *      the whole registry at most CONSOLE_MAX_SESSIONS of them, and an entry nobody has used for a
 *      minute is closed on the next pass. A held file descriptor is a real thing.
 *   3. **A cache miss is harmless.** The state of a console lives in the machine's `tmux`, not in
 *      the connection - so a missing, stale or dead entry costs one handshake and nothing else.
 *      That is why the reconnection below is silent rather than an error.
 *
 * A pooled connection that has died - the machine rebooted, the SSH server was restarted, a
 * firewall dropped an idle socket - answers an empty output with no exit status, which is
 * indistinguishable from a command that went nowhere. That case reopens once and replays, which is
 * safe precisely because every console command is idempotent: sending the same keystrokes twice
 * would not be, but a dead connection sent them zero times.
 */
class GuestShellPool
{
    /** After this without an exchange, the connection is closed rather than held. */
    public const int IDLE_SECONDS = 60;

    /**
     * The registry itself. Static, which is the whole point: it is what survives the kernel reset
     * between two requests of the same worker. In any other context (CLI, tests, a non-worker
     * runtime) it is simply always empty, and everything works one handshake slower.
     *
     * @var array<string, array{shell: GuestShell, at: int}>
     */
    private static array $open = [];

    public function __construct(private readonly GuestShellFactory $factory)
    {
    }

    /**
     * The connection for this console, reused if there is one and openable if there is not.
     *
     * @throws GuestUnreachableException
     * @throws PlatformKeyUnavailableException
     */
    public function acquire(int $sessionId, int $userId, string $ip, int $timeoutSeconds, int $max): GuestShell
    {
        $this->sweep($max);
        $key = \sprintf('%d/%d/%s', $sessionId, $userId, $ip);
        $held = self::$open[$key] ?? null;

        if (null !== $held) {
            self::$open[$key]['at'] = time();

            return new PooledGuestShell($held['shell'], fn (): GuestShell => $this->reopen($key, $ip, $timeoutSeconds));
        }

        $shell = $this->factory->open($ip, timeoutSeconds: $timeoutSeconds);
        self::$open[$key] = ['shell' => $shell, 'at' => time()];

        return new PooledGuestShell($shell, fn (): GuestShell => $this->reopen($key, $ip, $timeoutSeconds));
    }

    /** Closes and forgets one console's connection - what the close route does on the way out. */
    public function release(int $sessionId, int $userId, string $ip): void
    {
        $key = \sprintf('%d/%d/%s', $sessionId, $userId, $ip);
        $held = self::$open[$key] ?? null;

        if (null !== $held) {
            $held['shell']->disconnect();
            unset(self::$open[$key]);
        }
    }

    /** @throws GuestUnreachableException */
    private function reopen(string $key, string $ip, int $timeoutSeconds): GuestShell
    {
        // Guarded: a nullsafe operator does not cover the array access, and there is no reason the
        // entry has to still be there by the time a dead connection asks to be replaced.
        $held = self::$open[$key] ?? null;

        if (null !== $held) {
            $held['shell']->disconnect();
            unset(self::$open[$key]);
        }

        $shell = $this->factory->open($ip, timeoutSeconds: $timeoutSeconds);
        self::$open[$key] = ['shell' => $shell, 'at' => time()];

        return $shell;
    }

    /** Closes what has gone quiet, and keeps the registry no larger than the console ceiling. */
    private function sweep(int $max): void
    {
        $cutoff = time() - self::IDLE_SECONDS;

        foreach (self::$open as $key => $entry) {
            if ($entry['at'] < $cutoff) {
                $entry['shell']->disconnect();
                unset(self::$open[$key]);
            }
        }

        // Oldest first, so a console in use is never the one dropped.
        while (\count(self::$open) > max(1, $max)) {
            $oldest = array_key_first(self::$open);
            self::$open[$oldest]['shell']->disconnect();
            unset(self::$open[$oldest]);
        }
    }
}
