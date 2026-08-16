<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Talks to clamd over its TCP socket, using the INSTREAM command.
 *
 * INSTREAM rather than SCAN: SCAN takes a *path*, which means clamd has to see the same filesystem
 * as PHP - true of neither the production host (a separate container with no bind mount) nor the
 * dev profile. INSTREAM hands the bytes over the socket instead, so the two containers share
 * nothing but a network.
 *
 * The protocol, which is why this class is worth its own file:
 *
 *     zINSTREAM\0                        the command, NUL-terminated ("z" mode)
 *     <4-byte big-endian length><chunk>  repeated
 *     <4 zero bytes>                     end of stream
 *     stream: OK\0                       the reply - read by App\Service\ClamAvReply
 *
 * Every failure - refused connection, timeout, short write, truncated reply - becomes a
 * ClamAvUnavailableException, so the caller has exactly one thing to catch and no way to mistake
 * "could not scan" for "clean".
 */
class ClamAvClient
{
    /** 64 KiB, comfortably under clamd's default StreamMaxLength chunk handling. */
    private const int CHUNK_BYTES = 65536;

    public function __construct(
        private readonly float $timeoutSeconds = 30.0,
    ) {
    }

    /**
     * @param non-empty-string $dsn  clamav://host:port
     * @param string           $path the uploaded temp file, still on local disk
     *
     * @throws ClamAvUnavailableException
     */
    public function scan(string $dsn, string $path): ClamAvReply
    {
        [$host, $port] = $this->parse($dsn);

        $handle = @fopen($path, 'r');

        if (false === $handle) {
            throw new ClamAvUnavailableException(\sprintf('Could not read "%s" for scanning.', $path));
        }

        $socket = @stream_socket_client(
            \sprintf('tcp://%s:%d', $host, $port),
            $errorCode,
            $errorMessage,
            $this->timeoutSeconds,
        );

        if (false === $socket) {
            fclose($handle);

            throw new ClamAvUnavailableException(\sprintf('clamd unreachable at %s:%d (%d %s).', $host, $port, $errorCode, $errorMessage));
        }

        stream_set_timeout($socket, (int) $this->timeoutSeconds);

        try {
            $this->write($socket, "zINSTREAM\0");

            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_BYTES);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $this->write($socket, pack('N', \strlen($chunk)).$chunk);
            }

            // A zero-length chunk closes the stream and makes clamd answer.
            $this->write($socket, pack('N', 0));

            $reply = stream_get_contents($socket);

            if (false === $reply) {
                throw new ClamAvUnavailableException('clamd closed the connection without answering.');
            }

            return ClamAvReply::parse($reply);
        } finally {
            fclose($handle);
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $payload): void
    {
        $written = @fwrite($socket, $payload);

        if (false === $written || $written < \strlen($payload)) {
            throw new ClamAvUnavailableException('clamd rejected the stream mid-write.');
        }
    }

    /** @return array{string, int} */
    private function parse(string $dsn): array
    {
        $parts = parse_url($dsn);

        if (false === $parts || !isset($parts['host'])) {
            throw new ClamAvUnavailableException(\sprintf('Unparseable ANTIVIRUS_DSN "%s" - expected clamav://host:port.', $dsn));
        }

        return [$parts['host'], $parts['port'] ?? 3310];
    }
}
