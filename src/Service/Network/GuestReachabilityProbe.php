<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * Does anything answer at this address yet?
 *
 * A plain TCP connection with a short timeout, not ICMP: `ping` needs a raw socket the PHP
 * container does not have, and a machine that answers on port 22 has told us something far more
 * useful than one that answers an echo request - the address is right *and* the machine has
 * finished booting far enough to be worked on.
 *
 * This is what moves an allocation from `assigned` (MonCampus believes) to `confirmed` (a machine
 * demonstrably answered), and what the creation wizard waits on before running anything inside the
 * guest. A first boot takes its time, so the caller retries over a couple of minutes rather than
 * concluding from one refused connection.
 *
 * **It only works if the PHP container can reach the VM networks at all.** Where it cannot, the
 * probe never succeeds, guest accounts and post-installation are out of reach with it, and what
 * remains is the shared account cloud-init lays down. That is a property of the deployment, not of
 * this class - which is why it reports rather than throws.
 */
class GuestReachabilityProbe
{
    /** SSH first: it is the port everything downstream needs, and the one a Linux template opens. */
    public const int DEFAULT_PORT = 22;

    private const float CONNECT_TIMEOUT_SECONDS = 2.0;

    public function isReachable(string $ip, int $port = self::DEFAULT_PORT): bool
    {
        $socket = @fsockopen($ip, $port, $errorCode, $errorMessage, self::CONNECT_TIMEOUT_SECONDS);

        if (false === $socket) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Waits for a first boot. Returns as soon as something answers, or false once the budget is
     * spent - never throws, because "not yet" is an ordinary answer here and the screen offers to
     * check again.
     *
     * @param int $timeoutSeconds  total budget; a first boot with cloud-init routinely takes a
     *                             minute, so the default is generous rather than optimistic
     * @param int $intervalSeconds between attempts
     */
    public function waitUntilReachable(string $ip, int $port = self::DEFAULT_PORT, int $timeoutSeconds = 120, int $intervalSeconds = 5): bool
    {
        $deadline = time() + $timeoutSeconds;

        do {
            if ($this->isReachable($ip, $port)) {
                return true;
            }

            sleep($intervalSeconds);
        } while (time() < $deadline);

        return false;
    }
}
