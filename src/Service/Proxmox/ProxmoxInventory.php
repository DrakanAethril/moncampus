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
     * The storages of one node, as `GET /nodes/{n}/storage` reports them.
     *
     * @return list<array<string, mixed>>
     */
    public function storages(ProxmoxClient $client, string $node): array
    {
        return $client->get('/nodes/'.rawurlencode($node).'/storage')->rows();
    }

    /**
     * Every ISO the host can install from, across its nodes and their storages.
     *
     * Unlike the templates, these genuinely need their own calls: an ISO is a file on a storage,
     * not a guest, so nothing in `/cluster/resources` knows about it. Only storages that advertise
     * `iso` content are asked, which is what keeps the count of calls down to the handful that can
     * answer.
     *
     * @param list<ProxmoxNode> $nodes
     *
     * @return list<ProxmoxStorageItem>
     */
    public function isoImages(ProxmoxClient $client, array $nodes): array
    {
        $images = [];

        foreach ($nodes as $node) {
            if (!$node->isOnline()) {
                continue;
            }

            foreach ($this->storages($client, $node->name) as $storage) {
                $read = ProxmoxResponse::of($storage);
                $name = $read->string('storage');

                if ('' === $name || !str_contains($read->string('content'), 'iso')) {
                    continue;
                }

                try {
                    $rows = $client->get(
                        \sprintf('/nodes/%s/storage/%s/content', rawurlencode($node->name), rawurlencode($name)),
                        ['content' => 'iso'],
                    )->rows();
                } catch (ProxmoxUnavailableException) {
                    // A storage that is declared but offline is not a reason to show no ISO at all.
                    continue;
                }

                foreach ($rows as $row) {
                    $images[] = ProxmoxStorageItem::fromRow($row, $node->name, $name);
                }
            }
        }

        usort($images, static fn (ProxmoxStorageItem $a, ProxmoxStorageItem $b): int => strnatcasecmp($a->filename, $b->filename));

        return $images;
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
