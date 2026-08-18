<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * One way in which the registry and the hypervisor disagree.
 *
 * Four kinds, and they are four different problems with four different remedies - which is why the
 * gaps card lists them by kind rather than as one undifferentiated list of "issues":
 *
 *   conflict   - two machines carry the same address. Nothing the registry does fixes this; it is a
 *                network fault, and the remedy is in Proxmox.
 *   discovered - a machine carries an address the registry never handed out. Somebody created it by
 *                hand. Adopting it stops the registry claiming that address is free.
 *   orphan     - the registry holds an address no machine carries any more. The application does
 *                not destroy machines and so is never in the loop when one disappears - **the scan
 *                is the only mechanism that ever notices**, and without it a range never empties.
 *   moved      - the registry says the address belongs to one machine, the scan finds another
 *                carrying it. Usually a rebuilt machine reusing an address.
 *
 * None of these actions ever writes to Proxmox. They only bring the registry back into agreement
 * with what exists.
 */
final readonly class AddressGap
{
    public const string CONFLICT = 'conflict';
    public const string DISCOVERED = 'discovered';
    public const string ORPHAN = 'orphan';
    public const string MOVED = 'moved';

    /**
     * @param list<DiscoveredAddress> $guests the machines involved - two or more for a conflict,
     *                                        one for a discovery or a move, none for an orphan
     */
    public function __construct(
        public string $kind,
        public string $ip,
        public array $guests = [],
        public ?int $registeredVmid = null,
        public ?string $registeredHostname = null,
    ) {
    }

    public function labelKey(): string
    {
        return match ($this->kind) {
            self::CONFLICT => 'ipGapConflictLabel',
            self::DISCOVERED => 'ipGapDiscoveredLabel',
            self::ORPHAN => 'ipGapOrphanLabel',
            self::MOVED => 'ipGapMovedLabel',
            default => 'ipGapUnknownLabel',
        };
    }

    public function badgeModifier(): string
    {
        return match ($this->kind) {
            self::CONFLICT => 'red',
            self::DISCOVERED => 'blue',
            self::ORPHAN => 'gold',
            self::MOVED => 'purple',
            default => 'gray',
        };
    }
}
