<?php

declare(strict_types=1);

namespace App\Tests\Service\Guest;

use App\Service\Guest\GuestSshSession;
use App\Service\Guest\GuestUnreachableException;
use phpseclib3\Crypt\EC;
use PHPUnit\Framework\TestCase;

/**
 * The property under test is a **bound**, not a behaviour: a machine that does not answer must be
 * given up on well inside PHP's `max_execution_time`, which is 30 seconds here.
 *
 * It is worth a real socket rather than a double because the failure it pins is fatal and therefore
 * uncatchable: a MaxExecutionTimeError is raised by the engine, not thrown by the code, so no
 * `catch (GuestUnreachableException)` further up can turn it into the message the screen is built
 * to show. That is exactly what reached production on the guest accounts screen - the machine had
 * been created but was not answering SSH yet, and the screen died at 30 seconds instead of saying
 * so.
 *
 * The silent port is the shape that matters. A *refused* port fails instantly on its own and would
 * prove nothing; a port that accepts the connection and then never sends its SSH banner is what a
 * machine mid-boot, or a filtered network, actually looks like.
 */
class GuestSshSessionTest extends TestCase
{
    public function testAPortThatAcceptsAndThenSaysNothingIsGivenUpOn(): void
    {
        // Port 0: the kernel picks a free one, so the test cannot collide with anything on the
        // machine that runs it. Never accept()ed - the backlog completes the TCP handshake for us,
        // which is precisely the silence we want to time.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, 'the test needs a listening socket: '.$error);

        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        // A genuine key, generated rather than faked: an unparsable one raises the same exception
        // for a different reason, and the test would pass while proving nothing about the network.
        $session = new GuestSshSession('127.0.0.1', 'root', EC::createKey('Ed25519')->toString('PKCS8'), $port, connectTimeoutSeconds: 1);

        $startedAt = microtime(true);

        try {
            $session->run('true');
            self::fail('A port that never speaks SSH must not pass for a reachable machine.');
        } catch (GuestUnreachableException) {
            $elapsed = microtime(true) - $startedAt;
        } finally {
            $session->disconnect();
            fclose($server);
        }

        // Both bounds carry weight. The upper one is the bug: it must fail inside the budget it was
        // given, far from PHP's 30 seconds. The lower one proves it actually waited on the network
        // rather than tripping over something else on its way there.
        self::assertLessThan(10.0, $elapsed, \sprintf('a one-second budget must not take %.1fs', $elapsed));
        self::assertGreaterThan(0.5, $elapsed, 'the failure came from somewhere other than the silent port');
    }
}
