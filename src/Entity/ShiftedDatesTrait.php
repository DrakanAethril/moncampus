<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * « À relire »: the dates of this row were moved by the school-year import
 * (design/validated/school-year-import.md, R4) and nobody has read them since.
 *
 * Set by the import only. Cleared by changing one of the dates (the setters call
 * clearShiftedMarkIfChanged()) or by « Confirmer ces dates » on the screen that shows them.
 */
trait ShiftedDatesTrait
{
    #[ORM\Column(name: 'shifted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $shiftedAt = null;

    public function getShiftedAt(): ?\DateTimeImmutable
    {
        return $this->shiftedAt;
    }

    public function isShifted(): bool
    {
        return null !== $this->shiftedAt;
    }

    public function markShifted(\DateTimeImmutable $at): static
    {
        $this->shiftedAt = $at;

        return $this;
    }

    public function confirmShiftedDates(): static
    {
        $this->shiftedAt = null;

        return $this;
    }

    /** A form re-submitting the same day is not a change: compared by value, never by identity. */
    private function clearShiftedMarkIfChanged(?\DateTimeImmutable $before, ?\DateTimeImmutable $after): void
    {
        if ($before?->format('Y-m-d H:i:s') !== $after?->format('Y-m-d H:i:s')) {
            $this->shiftedAt = null;
        }
    }
}
