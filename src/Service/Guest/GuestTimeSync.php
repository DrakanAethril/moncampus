<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * Points a machine's clock at the gateway of its own VLAN.
 *
 * A machine deployed on a school VLAN usually cannot reach the public NTP pool a cloud image ships
 * with: the pool is unreachable, chrony never steps the clock, and the drift shows up much later as
 * things nobody connects to time - a certificate refused, an SSH key rejected, a submission
 * timestamped on the wrong day. The gateway is the one address every machine of a range can always
 * reach, so it is what the range's machines are told to ask.
 *
 * **The main file is edited rather than a drop-in written**, and that is a deliberate trade. The
 * two families disagree on where a drop-in goes and on whether one is included at all
 * (`/etc/chrony/conf.d` on Debian, `/etc/chrony.d` on RHEL, and older images include neither), so a
 * drop-in is the shape most likely to be written correctly and read by nobody - the exact failure
 * this deployment chain has already paid for twice. Commenting the existing sources and appending a
 * marked block works the same way on both, and can be read back with `cat`.
 *
 * The block carries its markers so a second run replaces it instead of stacking: the deletion comes
 * first, the commenting second, the append last, which is what makes the whole thing idempotent.
 */
class GuestTimeSync
{
    /**
     * Configures chrony and restarts it.
     *
     * @throws GuestCommandFailedException when chrony is not installed, or when the machine refuses
     *                                     one of the steps - the caller decides how loud that is
     * @throws GuestUnreachableException
     */
    public function configure(GuestShell $shell, string $gateway): void
    {
        if (false === filter_var($gateway, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an IPv4 address.', $gateway));
        }

        $command = $this->command($gateway);
        $result = $shell->run($command);

        if (!$result->isUndetermined() && !$result->isSuccess()) {
            throw GuestCommandFailedException::of($command, $result);
        }
    }

    private function command(string $gateway): string
    {
        // `prefer` and `iburst`: the gateway is the source that matters, and asking four times in
        // the first seconds is what makes a freshly cloned machine right within the minute rather
        // than within the hour.
        $server = \sprintf('server %s iburst prefer', $gateway);

        return implode("\n", [
            'set -e',
            'conf=""',
            'for candidate in /etc/chrony/chrony.conf /etc/chrony.conf; do',
            '    if [ -f "$candidate" ]; then conf="$candidate"; break; fi',
            'done',
            // Said rather than skipped: a template without chrony is a template to fix, and the
            // whole point of this step is that nothing about the clock stays quiet.
            'if [ -z "$conf" ]; then echo "chrony is not installed on this machine: no chrony.conf found."; exit 3; fi',
            'sed -i "/# >>> moncampus/,/# <<< moncampus/d" "$conf"',
            'sed -i -e "s/^[[:space:]]*\\(pool[[:space:]]\\)/# \\1/" -e "s/^[[:space:]]*\\(server[[:space:]]\\)/# \\1/" "$conf"',
            \sprintf('printf "%%s\\n" "# >>> moncampus" %s "# <<< moncampus" >> "$conf"', escapeshellarg($server)),
            // chronyd on RHEL, chrony on Debian - one of the two names is the service.
            'systemctl restart chronyd 2>/dev/null || systemctl restart chrony',
            // Best effort: the clock is already being disciplined by the restart above, and a
            // machine whose offset is too large for a slew should jump rather than crawl there.
            'chronyc makestep >/dev/null 2>&1 || true',
        ]);
    }
}
