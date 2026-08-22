<?php

declare(strict_types=1);

namespace App\Service\Guest;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 * A real SSH session into one machine, authenticated by the platform key.
 *
 * phpseclib rather than ext-ssh2: it is pure PHP (nothing to add to the image), MIT (compatible
 * with this application's AGPL), and it is the library that gets maintained.
 *
 * Three guards are set on every command, and each of them exists because of a specific way a
 * post-installation script hangs rather than fails:
 *
 *   - **stdin is closed** (`< /dev/null`). A command that stops to ask a question otherwise waits
 *     for an answer nobody is there to give.
 *   - **`DEBIAN_FRONTEND=noninteractive`**, which is what stops apt opening a dialog at all.
 *   - **a five-minute ceiling**, so the one that still finds a way to wait is cut off rather than
 *     holding a worker until the request times out.
 *
 * A non-zero exit code is not an exception - a command that fails has run, and its output is the
 * useful part. Only an unusable connection raises.
 */
class GuestSshSession implements GuestShell
{
    public const int DEFAULT_TIMEOUT_SECONDS = 300;

    /**
     * How long opening the session may take, and it is a different question from how long a command
     * may run - which is why it is a second constant rather than a reuse of the first.
     *
     * Chosen against PHP's `max_execution_time` of 30 seconds rather than against the network: a
     * machine that has not finished booting accepts the connection and then says nothing, so the
     * wait is paid in full, and a fatal MaxExecutionTimeError cannot be caught into the message the
     * screen is built to show. Five seconds is generous for a TCP handshake and a key exchange on
     * the school's own network, and leaves room for the caller to try more than one key.
     */
    public const int CONNECT_TIMEOUT_SECONDS = 5;

    private ?SSH2 $ssh = null;

    /**
     * @param string $privateKey the platform key in PEM, opened by PlatformKeyProvider for the
     *                           duration of this session and never stored
     */
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $privateKey,
        private readonly int $port = 22,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $connectTimeoutSeconds = self::CONNECT_TIMEOUT_SECONDS,
    ) {
    }

    public function run(string $command): GuestCommandResult
    {
        // Wrapping - and elevation, when this session is not root's - is GuestCommandLine's, in
        // one place: a command someone forgot to elevate fails silently into an output nobody reads.
        return $this->execute(GuestCommandLine::build($command, $this->username));
    }

    public function runAsSelf(string $command): GuestCommandResult
    {
        return $this->execute(GuestCommandLine::buildAsSelf($command));
    }

    private function execute(string $line): GuestCommandResult
    {
        $ssh = $this->connection();
        $output = $ssh->exec($line);

        if (!\is_string($output)) {
            // The session went away mid-command - a script that reboots does exactly this. Not a
            // failure: it ran, and how it ended is unknown.
            return new GuestCommandResult('', null);
        }

        // getExitStatus() answers false when the peer closed before sending one, which is the same
        // "it ran, verdict unknown" state.
        $status = $ssh->getExitStatus();

        return new GuestCommandResult($output, false === $status ? null : $status);
    }

    public function disconnect(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
    }

    /** @throws GuestUnreachableException */
    private function connection(): SSH2
    {
        if (null !== $this->ssh) {
            return $this->ssh;
        }

        // The socket is ours rather than phpseclib's, and that is the whole of the timeout fix -
        // see openSocket().
        $socket = $this->openSocket();

        try {
            $ssh = new SSH2($socket, $this->port, $this->connectTimeoutSeconds);
            $key = PublicKeyLoader::load($this->privateKey);

            // PublicKeyLoader::load() is typed as the common AsymmetricKey because it reads both
            // halves; what came out of PlatformKeyProvider is a private one, and login() takes
            // nothing else. Checked rather than cast: a public key here would fail authentication
            // with a message about credentials rather than about the key being the wrong half.
            if (!$key instanceof PrivateKey) {
                throw new GuestUnreachableException('The platform key is not a usable private key.');
            }

            if (!$ssh->login($this->username, $key)) {
                throw new GuestUnreachableException(\sprintf('%s@%s refused the platform key.', $this->username, $this->host));
            }
        } catch (GuestUnreachableException $exception) {
            $this->close($socket);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->close($socket);

            throw new GuestUnreachableException(\sprintf('Could not open an SSH session to %s: %s', $this->host, $exception->getMessage()), previous: $exception);
        }

        // Only now, and on both layers, because the socket carries its own timeout: the session is
        // open, and what runs on it may be a post-installation script that legitimately takes
        // minutes.
        stream_set_timeout($socket, $this->timeoutSeconds);
        $ssh->setTimeout($this->timeoutSeconds);

        return $this->ssh = $ssh;
    }

    /**
     * Connects the TCP socket under a bound this class controls, and arms the read timeout that
     * phpseclib's own does not cover.
     *
     * **Two waits, and the constructor's `$timeout` bounds only one of them** - read in phpseclib's
     * source rather than assumed. Its banner loop calls `stream_select()` with the remaining
     * budget, then falls through to a blocking `stream_get_line()` **without testing whether the
     * select actually returned anything**. So a machine that accepts the connection and never sends
     * its SSH banner - a machine mid-boot, the ordinary state of one this application just created -
     * costs the budget *plus* `default_socket_timeout`, which is 60 seconds. Measured here at 61.4s
     * against a one-second budget, and PHP kills the request fatally at 30.
     *
     * `stream_set_timeout()` is what bounds that second wait, and it can only be armed on a socket
     * one holds - hence opening it here and handing it over. phpseclib takes a resource in place of
     * a host name and skips its own `fsockopen()` when it gets one.
     *
     * @return resource
     *
     * @throws GuestUnreachableException when the address does not accept a connection at all
     */
    private function openSocket()
    {
        $socket = @stream_socket_client(
            \sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $error,
            $this->connectTimeoutSeconds,
        );

        if (false === $socket) {
            throw new GuestUnreachableException(\sprintf('Could not reach %s:%d: %s', $this->host, $this->port, '' !== $error ? $error : \sprintf('error %d', $errno)));
        }

        stream_set_timeout($socket, $this->connectTimeoutSeconds);

        return $socket;
    }

    /**
     * phpseclib closes the socket itself on some failure paths, so this is guarded: fclose() on an
     * already-closed resource is a warning, and a warning here would replace the message saying
     * what is actually wrong with the machine.
     *
     * @param resource $socket
     */
    private function close($socket): void
    {
        if (\is_resource($socket)) {
            fclose($socket);
        }
    }
}
