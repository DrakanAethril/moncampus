<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CompanySearch\Distance;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * « Trouver une entreprise » in Twig: the distance as the crow flies, and the Google Maps key the
 * map partial needs. An empty key draws no map at all - the addresses and itinerary links remain,
 * which is the whole page without Google.
 */
class CompanySearchExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire('%env(GOOGLE_MAPS_API_KEY)%')]
        private readonly string $googleMapsApiKey,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('distance_km', Distance::km(...)),
            new TwigFunction('google_maps_api_key', fn (): string => trim($this->googleMapsApiKey)),
        ];
    }
}
