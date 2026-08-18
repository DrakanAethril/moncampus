<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * Builds what gets written into a guest before its first boot: the name, the fixed address, the
 * bridge and the VLAN, in the two shapes Proxmox accepts.
 *
 * **A QEMU virtual machine has no hostname option.** Proxmox derives cloud-init's `local-hostname`
 * from the VM's *name*, so the single field on screen has to satisfy hostname rules rather than
 * VM-name rules: lowercase, digits and hyphens, 63 characters, no hyphen at either end. Nothing on
 * the Proxmox side enforces that - a machine called `Serveur Web (TP)` is created happily and boots
 * with a hostname nobody can resolve or ssh to. So it is enforced here, before the call goes out.
 * LXC does have a `hostname` parameter, and it gets the same treatment for the same reason.
 *
 * `sshkeys` must be URL-encoded. A key pasted as-is fails opaquely - Proxmox says nothing that
 * points at the encoding - which is exactly the kind of failure worth spending one line to avoid.
 *
 * Pure: no client, no entity, no injection. Given the same inputs it produces the same strings, and
 * the pair of shapes can be compared side by side in a test.
 */
class GuestNetworkConfigurator
{
    private const int MAX_HOSTNAME_LENGTH = 63;

    /**
     * The `PUT /nodes/{n}/qemu/{id}/config` payload that gives a cloned machine its identity.
     *
     * @return array<string, string>
     *
     * @throws InvalidHostnameException when the name would not survive as a hostname
     * @throws \InvalidArgumentException on an address or gateway that is not IPv4
     */
    public function qemuParameters(
        string $hostname,
        string $ip,
        int $prefixLength,
        string $gateway,
        string $bridge,
        ?int $vlan,
        ?string $sshKey = null,
        ?string $cloudInitUser = null,
    ): array {
        $this->assertHostname($hostname);
        $this->assertAddress($ip, $gateway);

        $parameters = [
            // Both the VM name and, through it, the guest's hostname - see the class docblock.
            'name' => $hostname,
            'ipconfig0' => \sprintf('ip=%s/%d,gw=%s', $ip, $prefixLength, $gateway),
            'net0' => $this->interfaceString('virtio', $bridge, $vlan),
        ];

        if (null !== $cloudInitUser && '' !== $cloudInitUser) {
            $parameters['ciuser'] = $cloudInitUser;
        }

        if (null !== $sshKey && '' !== trim($sshKey)) {
            $parameters['sshkeys'] = rawurlencode(trim($sshKey));
        }

        return $parameters;
    }

    /**
     * The equivalent for a container, where everything lives in `net0` and the hostname is its own
     * parameter. No `ipconfig0`: a container has no cloud-init drive.
     *
     * @return array<string, string>
     */
    public function lxcParameters(
        string $hostname,
        string $ip,
        int $prefixLength,
        string $gateway,
        string $bridge,
        ?int $vlan,
        ?string $sshKey = null,
    ): array {
        $this->assertHostname($hostname);
        $this->assertAddress($ip, $gateway);

        $net = \sprintf('name=eth0,bridge=%s,ip=%s/%d,gw=%s', $bridge, $ip, $prefixLength, $gateway);

        if (null !== $vlan) {
            $net .= ',tag='.$vlan;
        }

        $parameters = ['hostname' => $hostname, 'net0' => $net];

        if (null !== $sshKey && '' !== trim($sshKey)) {
            $parameters['ssh-public-keys'] = trim($sshKey);
        }

        return $parameters;
    }

    public function isValidHostname(string $hostname): bool
    {
        if ('' === $hostname || \strlen($hostname) > self::MAX_HOSTNAME_LENGTH) {
            return false;
        }

        return 1 === preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $hostname);
    }

    /**
     * Turns something a human typed into something a machine can be called - what the wizard offers
     * as it types, so the rule is met before it is explained.
     */
    public function suggestHostname(string $raw): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $raw);
        $ascii = false === $ascii ? $raw : $ascii;

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii));
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, self::MAX_HOSTNAME_LENGTH);
        $slug = rtrim($slug, '-');

        // Something unusable still has to yield something valid: the field is required, and an
        // empty suggestion would put the burden back on the person for no reason.
        return '' !== $slug ? $slug : 'vm';
    }

    private function interfaceString(string $model, string $bridge, ?int $vlan): string
    {
        $net = \sprintf('%s,bridge=%s', $model, $bridge);

        // `tag=` with no value is not "no tag" - Proxmox refuses it outright.
        return null !== $vlan ? $net.',tag='.$vlan : $net;
    }

    private function assertHostname(string $hostname): void
    {
        if (!$this->isValidHostname($hostname)) {
            throw new InvalidHostnameException(\sprintf('"%s" cannot be a hostname.', $hostname));
        }
    }

    private function assertAddress(string $ip, string $gateway): void
    {
        foreach ([$ip, $gateway] as $address) {
            if (false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not an IPv4 address.', $address));
            }
        }
    }
}
