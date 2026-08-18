<?php

declare(strict_types=1);

namespace App\Service\Network;

/**
 * The arithmetic under every address the console hands out: parsing a CIDR, deriving its usable
 * bounds, counting what is left, finding the next free one, and telling whether two declarations
 * overlap.
 *
 * Pure - no state, no injection, no database - which is why it was the first file of the feature
 * to be written, and why it could be tested before anything else existed.
 *
 * **IPv4 only, deliberately.** Every consumer downstream is v4-shaped: cloud-init's `ipconfig0`,
 * the LXC `net0` string, the scanner that reads them back, and the registry's notion of "the next
 * one". Half-supporting v6 would produce a console that offers addresses it cannot configure, so a
 * v6 CIDR is refused here rather than accepted and mishandled later.
 *
 * Two arithmetic details are easy to get wrong and are pinned by the tests:
 *  - a /31 has **two** usable addresses and a /32 has **one** (RFC 3021 - point-to-point links have
 *    no network/broadcast pair). Subtracting two unconditionally answers zero or minus one.
 *  - the usable bounds are a *declaration*, not a derivation: a range says where its assignable
 *    window starts and ends, and that window is narrower than the CIDR precisely so the gateway and
 *    the hand-addressed equipment are never offered.
 */
class IpRangeCalculator
{
    public function isValidAddress(string $address): bool
    {
        return false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4);
    }

    public function isValidCidr(string $cidr): bool
    {
        return null !== $this->parse($cidr);
    }

    /** The prefix length, or null when the CIDR is unusable. */
    public function prefixLength(string $cidr): ?int
    {
        return $this->parse($cidr)['prefix'] ?? null;
    }

    /** `10.30.20.57/24` yields `10.30.20.0` - the declaration is normalised rather than rejected. */
    public function networkAddress(string $cidr): ?string
    {
        $parsed = $this->parse($cidr);

        return null === $parsed ? null : $this->fromLong($parsed['network']);
    }

    public function contains(string $cidr, string $address): bool
    {
        $parsed = $this->parse($cidr);
        $value = $this->toLong($address);

        if (null === $parsed || null === $value) {
            return false;
        }

        return $value >= $parsed['network'] && $value <= $parsed['broadcast'];
    }

    /**
     * What a range would offer if nothing were reserved for the infrastructure - the starting point
     * the form prefills, not the answer it keeps.
     *
     * @return array{0: string, 1: string}|null
     */
    public function defaultUsableBounds(string $cidr): ?array
    {
        $parsed = $this->parse($cidr);

        if (null === $parsed) {
            return null;
        }

        // 31 and 32 keep every address: there is no network/broadcast pair to remove.
        if ($parsed['prefix'] >= 31) {
            return [$this->fromLong($parsed['network']), $this->fromLong($parsed['broadcast'])];
        }

        return [$this->fromLong($parsed['network'] + 1), $this->fromLong($parsed['broadcast'] - 1)];
    }

    /** How many addresses the declared window holds, both bounds included. Reversed bounds hold none. */
    public function capacity(string $firstUsable, string $lastUsable): int
    {
        $first = $this->toLong($firstUsable);
        $last = $this->toLong($lastUsable);

        if (null === $first || null === $last || $last < $first) {
            return 0;
        }

        return $last - $first + 1;
    }

    /**
     * The first address of the window nobody holds.
     *
     * @param list<string> $taken every address already spoken for, from all three sources the
     *                            design names: the registry, Proxmox, and anything declared
     *                            external. Entries outside the window are ignored rather than
     *                            treated as errors - a printer at .5 is not this range's business.
     */
    public function nextFree(string $firstUsable, string $lastUsable, array $taken): ?string
    {
        $first = $this->toLong($firstUsable);
        $last = $this->toLong($lastUsable);

        if (null === $first || null === $last || $last < $first) {
            return null;
        }

        $held = [];
        foreach ($taken as $address) {
            $value = $this->toLong($address);

            if (null !== $value) {
                $held[$value] = true;
            }
        }

        for ($value = $first; $value <= $last; ++$value) {
            if (!isset($held[$value])) {
                return $this->fromLong($value);
            }
        }

        return null;
    }

    /** @param list<string> $taken */
    public function freeCount(string $firstUsable, string $lastUsable, array $taken): int
    {
        $first = $this->toLong($firstUsable);
        $last = $this->toLong($lastUsable);
        $capacity = $this->capacity($firstUsable, $lastUsable);

        if (0 === $capacity || null === $first || null === $last) {
            return 0;
        }

        $held = [];
        foreach ($taken as $address) {
            $value = $this->toLong($address);

            // Deduplicated, and only inside the window: counting an address twice, or counting one
            // that was never on offer, makes the "43 left" caption lie.
            if (null !== $value && $value >= $first && $value <= $last) {
                $held[$value] = true;
            }
        }

        return $capacity - \count($held);
    }

    /**
     * Whether two declarations share any address at all.
     *
     * An unparseable CIDR overlaps nothing: claiming otherwise would block every save on a screen
     * whose own validation has already refused it for a better reason.
     */
    public function overlaps(string $cidrA, string $cidrB): bool
    {
        $a = $this->parse($cidrA);
        $b = $this->parse($cidrB);

        if (null === $a || null === $b) {
            return false;
        }

        return $a['network'] <= $b['broadcast'] && $b['network'] <= $a['broadcast'];
    }

    /**
     * Numerically, not alphabetically. A registry sorted as strings puts .100 before .9 and looks
     * shuffled to the only people who read it.
     *
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    public function sortAddresses(array $addresses): array
    {
        usort($addresses, fn (string $a, string $b): int => ($this->toLong($a) ?? 0) <=> ($this->toLong($b) ?? 0));

        return $addresses;
    }

    public function toLong(string $address): ?int
    {
        if (!$this->isValidAddress($address)) {
            return null;
        }

        $value = ip2long($address);

        // ip2long() answers a signed 32-bit value on some builds; the unsigned reading is what the
        // comparisons here need, and PHP's int is 64-bit in this image.
        return false === $value ? null : ($value < 0 ? $value + 4294967296 : $value);
    }

    public function fromLong(int $value): string
    {
        return long2ip($value);
    }

    /**
     * @return array{network: int, broadcast: int, prefix: int}|null
     */
    private function parse(string $cidr): ?array
    {
        $parts = explode('/', $cidr);

        if (2 !== \count($parts) || '' === $parts[1] || !ctype_digit($parts[1])) {
            return null;
        }

        $prefix = (int) $parts[1];
        $address = $this->toLong($parts[0]);

        if (null === $address || $prefix > 32) {
            return null;
        }

        // A /0 mask is 0, and PHP's << on 32 shifts by 32 which is undefined territory - computed
        // through the subtraction instead, which stays exact for every prefix from 0 to 32.
        $size = 2 ** (32 - $prefix);
        $network = $address - ($address % $size);

        return ['network' => $network, 'broadcast' => $network + $size - 1, 'prefix' => $prefix];
    }
}
