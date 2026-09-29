<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EcoPositionPing;
use App\Entity\EcoRunner;
use App\Repository\EcoPositionPingRepository;

/**
 * A runner's GPS trace as every results figure reads it: the fixes in recording order, less the
 * ones EcoTraceCleaner::plausible() does not believe. The one door onto a trace for the results
 * screen, so the distance, the legs, the stops and the line on the map cannot each be drawn from a
 * different set of fixes.
 */
class EcoRunnerTrace
{
    public function __construct(
        private readonly EcoPositionPingRepository $pingRepository,
        private readonly EcoTraceCleaner $traceCleaner,
    ) {
    }

    /** @return list<EcoPositionPing> */
    public function of(EcoRunner $runner): array
    {
        return $this->traceCleaner->plausible($this->pingRepository->findForRunner($runner));
    }
}
