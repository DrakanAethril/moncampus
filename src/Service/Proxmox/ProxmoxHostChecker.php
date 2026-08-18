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
    public function __construct(
        private readonly ProxmoxClientFactory $clientFactory,
        private readonly ProxmoxInventory $inventory,
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
        try {
            $client = $this->clientFactory->operate($host);
            $version = $client->version()->string('version', '?');

            $nodes = $this->inventory->nodes($client);
            $guests = $this->inventory->guests($client);
            $summary = $this->inventory->summarise($guests, \count($nodes));

            return new ProxmoxCheckResult(
                true,
                \sprintf('Proxmox VE %s', $version),
                $version,
                $summary['nodes'],
                $summary['guests'],
                $summary['running'],
                $this->warnings($host, $client),
            );
        } catch (ProxmoxUnavailableException $exception) {
            return ProxmoxCheckResult::failure($exception->getMessage());
        }
    }

    /** @return list<ProxmoxCheckWarning> */
    private function warnings(ProxmoxHost $host, ProxmoxClient $client): array
    {
        $warnings = [];
        $pool = $host->getManagedPool();

        if (null !== $pool && '' !== $pool && !$client->poolExists($pool)) {
            $warnings[] = new ProxmoxCheckWarning('proxmoxCheckPoolMissingWarning', ['%pool%' => $pool]);
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
