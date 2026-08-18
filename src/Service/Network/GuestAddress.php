<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * What one guest's network configuration actually says, once read out of Proxmox's flat
 * comma-separated option strings.
 *
 * Every field is nullable because every field is genuinely optional in a Proxmox configuration: a
 * guest can be on DHCP, can have no `ipconfig0` at all, can have a bridge and no VLAN tag. The
 * scanner treats each absence as its own fact rather than as a parse failure.
 *
 * `bridge` and `vlan` matter as much as the address itself: matching a guest to a declared range
 * takes **two** criteria - the address falling inside the CIDR *and* the interface sitting on the
 * right bridge with the right tag. Without the second, two VLANs both numbered 10.30.x get mixed
 * together and the registry starts reporting conflicts that do not exist.
 */
final readonly class GuestAddress
{
    public function __construct(
        public ?string $ip,
        public ?string $cidrSuffix,
        public ?string $gateway,
        public ?string $macAddress,
        public ?string $bridge,
        public ?int $vlan,
        public bool $dhcp,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null, null, null, null, false);
    }

    public function hasFixedAddress(): bool
    {
        return null !== $this->ip && !$this->dhcp;
    }
}
