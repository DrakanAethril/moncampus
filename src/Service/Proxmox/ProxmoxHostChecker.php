<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

use App\Entity\ProxmoxHost;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs the connection test and writes down what it found.
 *
 * This is the only thing that moves a host's badge. Nothing probes at display: the hosts screen and
 * the hub read `lastCheckAt`/`lastCheckOk` and say how old they are, because sounding out N
 * hypervisors to draw one page makes that page as slow as the worst of them and as broken as the
 * one that is unplugged. "Tested 4 minutes ago" is the honest caption, and it is deliberate.
 *
 * The test does four things, in an order chosen so the first failure is the informative one:
 *   1. `GET /version` - transport, TLS and credentials at once.
 *   2. `GET /nodes` and `GET /cluster/resources` - the counters the cards show.
 *   3. the declared pool, if any - a typo there silently empties the scope guard.
 *   4. the provisioning credentials, if any - a second account that answers 403 would otherwise
 *      only be discovered by an administrator halfway through the creation wizard.
 */
class ProxmoxHostChecker
{
    /**
     * How long the whole sequence may take, weighed against PHP's `max_execution_time` of 30 s and
     * against the client's own per-call ceiling: a call started at the last moment still has to
     * finish inside the process's budget. Raising one of the two means re-checking the other.
     */
    private const float BUDGET_SECONDS = 15.0;

    public function __construct(
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxInventory $inventory,
        private readonly ProxmoxFailureMessage $failureMessage,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Tests the host and records the outcome on it. The caller flushes. */
    public function check(ProxmoxHost $host): ProxmoxCheckResult
    {
        $result = $this->run($host);

        $host->recordCheck($result->ok, $result->message, $result->version);
        $host->recordInventory($result->nodeCount, $result->guestCount, $result->runningCount);

        return $result;
    }

    /** Tests, records and persists - for the callers that have nothing else to flush. */
    public function checkAndFlush(ProxmoxHost $host): ProxmoxCheckResult
    {
        $result = $this->check($host);
        $this->entityManager->flush();

        return $result;
    }

    private function run(ProxmoxHost $host): ProxmoxCheckResult
    {
        // A budget for the whole sequence, not for each call. Bounding the calls one by one is not
        // enough: five of them at the client's own ceiling would still add up past PHP's
        // `max_execution_time`, and a fatal MaxExecutionTimeError cannot be caught, so the screen
        // would show nothing at all while Discord collected the alert. Checked between calls rather
        // than during one, which is the client's job.
        $deadline = microtime(true) + self::BUDGET_SECONDS;

        try {
            $client = $this->clientFactory->operate($host);
            $version = $client->version()->string('version', '?');

            $this->assertWithinBudget($deadline);
            $nodes = $this->inventory->nodes($client);

            $this->assertWithinBudget($deadline);
            $guests = $this->inventory->guests($client);
            $summary = $this->inventory->summarise($guests, \count($nodes));

            return new ProxmoxCheckResult(
                true,
                \sprintf('Proxmox VE %s', $version),
                $version,
                $summary['nodes'],
                $summary['guests'],
                $summary['running'],
                $this->warnings($host, $client, $deadline),
            );
        } catch (ProxmoxUnavailableException $exception) {
            // The readable half, not getMessage(): this string is stored on the host and printed
            // raw by the hosts list and the hub, so it is what an administrator reads.
            return ProxmoxCheckResult::failure($this->failureMessage->readable($exception));
        }
    }

    /**
     * @throws ProxmoxUnavailableException when there is no time left to start another call
     */
    private function assertWithinBudget(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw ProxmoxUnavailableException::tooSlow((int) self::BUDGET_SECONDS);
        }
    }

    /**
     * The two extra questions, asked only if there is budget left for them.
     *
     * They are genuinely optional: the host has already answered, the badge is already green, and
     * both of these say "reachable but misdeclared" rather than "broken". Spending the last of the
     * budget on them would risk turning a successful check into a blank screen, which is a far
     * worse trade than a missing warning.
     *
     * @return list<ProxmoxCheckWarning>
     */
    private function warnings(ProxmoxHost $host, ProxmoxClient $client, float $deadline): array
    {
        $warnings = [];
        $pool = $host->getManagedPool();

        if (microtime(true) >= $deadline) {
            return [new ProxmoxCheckWarning('proxmoxCheckIncompleteWarning', [])];
        }

        if (null !== $pool && '' !== $pool && !$client->poolExists($pool)) {
            $warnings[] = new ProxmoxCheckWarning('proxmoxCheckPoolMissingWarning', ['%pool%' => $pool]);
        }

        if (microtime(true) >= $deadline) {
            $warnings[] = new ProxmoxCheckWarning('proxmoxCheckIncompleteWarning', []);

            return $warnings;
        }

        if ($host->hasProvisionCredentials()) {
            try {
                $this->clientFactory->provision($host)->version();
            } catch (ProxmoxUnavailableException $exception) {
                $warnings[] = new ProxmoxCheckWarning('proxmoxCheckProvisionFailedWarning', ['%reason%' => $exception->getMessage()]);
            }
        }

        return $warnings;
    }
}
