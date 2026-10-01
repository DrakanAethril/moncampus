<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Repository\InternshipFormationCenterRepository;
use App\Service\Ign\GeocodedPlace;
use App\Service\Ign\IgnGeoplateformeClient;
use App\Service\Ign\IgnUnavailableException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Where the school is - the default département of « Trouver une entreprise », the point of
 * « Autour de l'école » and of the distances on a fiche.
 *
 * Read off the formation centre the UFA already fills (« Configuration › Centre de formation »),
 * geocoded once by the Géoplateforme and kept a month: an address does not move, and a school whose
 * address is not filled simply has no « Autour de l'école ».
 */
class SchoolLocation
{
    private const int TTL = 30 * 86400;

    public function __construct(
        private readonly InternshipFormationCenterRepository $centers,
        private readonly IgnGeoplateformeClient $ign,
        private readonly CacheInterface $cacheSirene,
    ) {
    }

    /** `87` for a school in Limoges; null when the centre has no postcode. */
    public function department(): ?string
    {
        $postalCode = $this->centers->findSingleton()?->getPostalCode();
        if (null === $postalCode || 1 !== preg_match('/^\d{5}$/', trim($postalCode))) {
            return null;
        }

        $postalCode = trim($postalCode);
        if (str_starts_with($postalCode, '97')) {
            return substr($postalCode, 0, 3);
        }

        if (str_starts_with($postalCode, '20')) {
            return (int) $postalCode >= 20200 ? '2B' : '2A';
        }

        return substr($postalCode, 0, 2);
    }

    public function place(): ?GeocodedPlace
    {
        $center = $this->centers->findSingleton();
        $address = trim(implode(' ', array_filter([$center?->getAddress(), $center?->getPostalCode(), $center?->getCity()])));
        if ('' === $address) {
            return null;
        }

        $address = preg_replace('/\s+/', ' ', $address) ?? $address;

        return $this->cacheSirene->get('school_place_'.sha1($address), function (ItemInterface $item) use ($address): ?GeocodedPlace {
            $item->expiresAfter(self::TTL);

            try {
                return $this->ign->places($address, null, 1)[0] ?? null;
            } catch (IgnUnavailableException) {
                // Not cached for a month: try again in an hour.
                $item->expiresAfter(3600);

                return null;
            }
        });
    }
}
