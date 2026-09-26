<?php

declare(strict_types=1);

namespace App\Service\Equipment;

/**
 * One way of reading what somebody typed as an equipment code: a number, and the check digit they
 * typed with it if they typed one.
 */
final readonly class EquipmentCodeCandidate
{
    public function __construct(
        public int $number,
        public ?int $checkDigit,
    ) {
    }

    /** No check digit typed is not an error - there is simply nothing to verify. */
    public function isValid(): bool
    {
        return null === $this->checkDigit || EquipmentCode::checkDigit($this->number) === $this->checkDigit;
    }
}
