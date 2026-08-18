<?php

declare(strict_types=1);

namespace App\Service\Network;

use App\Service\Proxmox\ProxmoxGuest;

/**
 * Reads a guest's addressing out of the flat option strings Proxmox keeps it in.
 *
 * Two shapes, because Proxmox has two:
 *  - **QEMU** splits it in half. `net0` carries the interface (`virtio=<mac>,bridge=vmbr0,tag=20`)
 *    and `ipconfig0` carries what cloud-init will apply (`ip=10.30.20.54/24,gw=10.30.20.1`). A
 *    guest can perfectly well have the first and not the second.
 *  - **LXC** puts everything in `net0`, including `ip=` and `gw=`.
 *
 * Every field is optional, and each absence is its own fact rather than a failure: no `ipconfig0`
 * means a machine addressed by hand inside the guest - which, in a fleet with no Windows template,
 * is half the machines. Treating that as a parse error would have the scanner report them as
 * unaddressed instead of as unknown.
 *
 * The bridge and the VLAN are read for a reason that only shows up later: matching a guest to a
 * declared range needs **two** criteria, the address falling inside the CIDR *and* the interface
 * being on the right bridge with the right tag. Without the second, two ranges both numbered
 * 10.30.x on different VLANs are indistinguishable.
 */
class GuestAddressReader
{
    /**
     * @param array<string, mixed> $config the guest's /config payload
     * @param string               $type   ProxmoxGuest::TYPE_QEMU or TYPE_LXC
     */
    public function read(array $config, string $type): GuestAddress
    {
        $net = $this->options($config['net0'] ?? null);

        if (ProxmoxGuest::TYPE_LXC === $type) {
            // Everything in one string, and the MAC under its own key name.
            return $this->build($net, $net, $net['hwaddr'] ?? null);
        }

        $ipConfig = $this->options($config['ipconfig0'] ?? null);

        // The MAC is the value of whichever driver key is present - virtio, e1000, rtl8139… - so it
        // is found by shape rather than by name.
        return $this->build($net, $ipConfig, $this->macIn($net));
    }

    /**
     * The name the guest will answer to. QEMU derives cloud-init's local-hostname from the *VM
     * name*, so there is no hostname option to read there; LXC has one of its own.
     *
     * @param array<string, mixed> $config
     */
    public function hostname(array $config, string $type): ?string
    {
        $key = ProxmoxGuest::TYPE_LXC === $type ? 'hostname' : 'name';
        $value = $config[$key] ?? null;

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    /**
     * @param array<string, string> $net
     * @param array<string, string> $addressing
     */
    private function build(array $net, array $addressing, ?string $mac): GuestAddress
    {
        $ip = $addressing['ip'] ?? null;
        $dhcp = 'dhcp' === $ip;

        $address = null;
        $suffix = null;

        if (null !== $ip && !$dhcp) {
            $parts = explode('/', $ip, 2);
            $address = filter_var($parts[0], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) ? $parts[0] : null;
            $suffix = $parts[1] ?? null;
        }

        $vlan = $net['tag'] ?? null;

        return new GuestAddress(
            $address,
            $suffix,
            $addressing['gw'] ?? null,
            null !== $mac ? strtoupper($mac) : null,
            $net['bridge'] ?? null,
            null !== $vlan && ctype_digit($vlan) ? (int) $vlan : null,
            $dhcp,
        );
    }

    /**
     * Proxmox stores these as `key=value,key=value` with no promised order, and a value can itself
     * contain a colon (a MAC does).
     *
     * @return array<string, string>
     */
    private function options(mixed $raw): array
    {
        if (!\is_string($raw) || '' === $raw) {
            return [];
        }

        $options = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode('=', $pair, 2);

            if (2 === \count($parts)) {
                $options[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $options;
    }

    /** @param array<string, string> $net */
    private function macIn(array $net): ?string
    {
        foreach ($net as $key => $value) {
            // A MAC is the only value in this string shaped like six colon-separated hex pairs, so
            // matching on the value rather than on the driver name covers every NIC model without
            // this class having to know their list.
            if ('bridge' !== $key && 1 === preg_match('/^[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}$/', $value)) {
                return $value;
            }
        }

        return null;
    }
}
