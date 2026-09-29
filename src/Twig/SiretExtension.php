<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Sirene\Siret;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

// Exposes App\Service\Sirene\Siret::format() to Twig: a SIRET is read and compared by eye, and
// « 489 319 103 00037 » is how the SIREN inside it shows - the same grouping on every screen.
class SiretExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('siret', Siret::format(...)),
        ];
    }
}
