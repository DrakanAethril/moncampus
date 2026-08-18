<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * Reads what a host holds, through a client that is already authenticated. No caching, no storage:
 * every screen that needs the list asks for it and gets the truth at that instant.
 *
 * `guests()` is one call for the whole cluster - `/cluster/resources?type=vm` covers every node,
 * both QEMU and LXC, and carries the `template` flag, so listing machines, counting them and
 * finding the clonable images cost exactly one request between them.
 */
class ProxmoxInventory
{
    /** @return list<ProxmoxNode> */
    public function nodes(ProxmoxClient $client): array
    {
        $nodes = array_map(ProxmoxNode::fromRow(...), $client->get('/nodes')->rows());
        usort($nodes, static fn (ProxmoxNode $a, ProxmoxNode $b): int => strnatcasecmp($a->name, $b->name));

        return $nodes;
    }

    /**
     * Every machine of the cluster, templates included - `templates()` and the machines list filter
     * the same array rather than issuing a second call.
     *
     * @return list<ProxmoxGuest>
     */
    public function guests(ProxmoxClient $client): array
    {
        $guests = array_map(
            ProxmoxGuest::fromRow(...),
            $client->get('/cluster/resources', ['type' => 'vm'])->rows(),
        );

        usort($guests, static fn (ProxmoxGuest $a, ProxmoxGuest $b): int => $a->vmid <=> $b->vmid);

        return $guests;
    }

    /**
     * @param list<ProxmoxGuest> $guests
     *
     * @return list<ProxmoxGuest>
     */
    public function templates(array $guests): array
    {
        return array_values(array_filter($guests, static fn (ProxmoxGuest $guest): bool => $guest->template));
    }

    /**
     * @param list<ProxmoxGuest> $guests
     *
     * @return list<ProxmoxGuest>
     */
    public function machines(array $guests): array
    {
        return array_values(array_filter($guests, static fn (ProxmoxGuest $guest): bool => !$guest->template));
    }

    /**
     * @param list<ProxmoxGuest> $guests
     *
     * @return array{nodes: int|null, guests: int, running: int} the snapshot recorded on the host
     */
    public function summarise(array $guests, ?int $nodeCount): array
    {
        $machines = $this->machines($guests);

        return [
            'nodes' => $nodeCount,
            'guests' => \count($machines),
            'running' => \count(array_filter($machines, static fn (ProxmoxGuest $guest): bool => $guest->isRunning())),
        ];
    }
}
