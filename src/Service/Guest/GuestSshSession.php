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
    ) {
    }

    public function run(string $command): GuestCommandResult
    {
        $ssh = $this->connection();

        // stdin closed and the frontend told not to ask: the two ways an unattended command turns
        // into a hang rather than a failure.
        $guarded = \sprintf('DEBIAN_FRONTEND=noninteractive %s < /dev/null 2>&1', $command);

        $output = $ssh->exec($guarded);

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

        try {
            $ssh = new SSH2($this->host, $this->port, $this->timeoutSeconds);
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
            throw $exception;
        } catch (\Throwable $exception) {
            throw new GuestUnreachableException(\sprintf('Could not open an SSH session to %s: %s', $this->host, $exception->getMessage()), previous: $exception);
        }

        $ssh->setTimeout($this->timeoutSeconds);

        return $this->ssh = $ssh;
    }
}
