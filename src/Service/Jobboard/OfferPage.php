<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardOffer;

/**
 * One page of the list, and the opaque cursor that continues it - never a page number: the data set
 * moves between two passes of the veille, and an OFFSET then skips or repeats rows.
 */
final readonly class OfferPage
{
    /** @param list<JobboardOffer> $offers */
    public function __construct(public array $offers, public ?string $nextCursor)
    {
    }
}
