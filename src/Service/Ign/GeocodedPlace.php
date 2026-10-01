<?php

declare(strict_types=1);

namespace App\Service\Ign;

/** One answer of the Géoplateforme's geocoder: a commune or an address, and where it stands. */
final readonly class GeocodedPlace
{
    public function __construct(
        public string $label,
        public ?string $postalCode,
        /** INSEE commune code (`87075`), whose first two or three characters are the département. */
        public ?string $cityCode,
        public float $latitude,
        public float $longitude,
    ) {
    }

    /** `87`, `2A`, `971` - read off the INSEE code, never off the postcode (Cedex, Corsica). */
    public function department(): ?string
    {
        if (null === $this->cityCode || \strlen($this->cityCode) < 5) {
            return null;
        }

        return str_starts_with($this->cityCode, '97') ? substr($this->cityCode, 0, 3) : substr($this->cityCode, 0, 2);
    }
}
