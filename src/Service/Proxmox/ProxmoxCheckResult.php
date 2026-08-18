<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * What one connection test learned. Returned to whoever asked (the form's "Tester la connexion",
 * the hosts screen's "Tester à nouveau", `app:proxmox:check`) so all three report the same thing in
 * the same words.
 *
 * `warnings` is not a softer kind of failure: a host can be perfectly reachable and still be
 * misdeclared - a managed pool that does not exist there, a provisioning account that answers 403.
 * Those are worth saying out loud at declaration time, because otherwise they only surface much
 * later as an action that quietly does nothing.
 */
final readonly class ProxmoxCheckResult
{
    /**
     * @param list<ProxmoxCheckWarning> $warnings
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $version = null,
        public ?int $nodeCount = null,
        public ?int $guestCount = null,
        public ?int $runningCount = null,
        public array $warnings = [],
    ) {
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
