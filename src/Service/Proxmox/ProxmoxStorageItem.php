<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * One file on a Proxmox storage - in practice an ISO image, from
 * `GET /nodes/{node}/storage/{storage}/content?content=iso`.
 *
 * The clonable *templates* deliberately do not come from here: they are the rows of
 * `/cluster/resources` whose `template` flag is set, so listing them costs no extra call. Only ISOs
 * need a per-storage read, because they are files rather than guests.
 */
final readonly class ProxmoxStorageItem
{
    public function __construct(
        /** `local:iso/debian-12.7.0-amd64-netinst.iso` - the volume id an installation call wants. */
        public string $volumeId,
        public string $storage,
        public string $node,
        public string $filename,
        public int $sizeBytes,
        public ?\DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, string $node, string $storage): self
    {
        $read = ProxmoxResponse::of($row);
        $volumeId = $read->string('volid');
        $created = $read->nullableInt('ctime');

        return new self(
            $volumeId,
            $storage,
            $node,
            // The volume id is `<storage>:iso/<filename>`; what a human reads is the tail.
            basename(str_replace(':', '/', $volumeId)),
            $read->int('size'),
            null !== $created ? (new \DateTimeImmutable())->setTimestamp($created) : null,
        );
    }
}
