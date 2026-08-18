<?php

declare(strict_types=1);

namespace App\Service\Guest;

use App\Enum\GuestAccountOrigin;

/**
 * One account MonCampus wants on a machine, as plain values.
 *
 * Separate from App\Entity\GuestAccount so the difference calculation can be exercised without a
 * database - and so that "what is wanted" and "what is recorded" stay two different things, which
 * is what makes a re-run of the syncer meaningful.
 */
final readonly class DesiredAccount
{
    public function __construct(
        public string $login,
        public GuestAccountOrigin $origin,
        public bool $sudo = false,
        public string $shell = '/bin/bash',
        public ?int $userId = null,
        public ?string $displayName = null,
    ) {
    }
}
