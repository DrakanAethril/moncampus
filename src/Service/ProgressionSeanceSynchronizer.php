<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SeanceInstance;
use App\Repository\ProgressionSeanceRepository;

/**
 * Pushes a change made on the class's copy of a séance back onto the progression lines that plan it.
 *
 * ProgressionBuilder copies a séance's fields when it adds a séquence to a progression, rather than
 * referencing them - that is deliberate, and the reason is in its own docblock: editing this class's
 * planning must not rewrite what another class reads. But "copied" also meant "frozen": once the
 * séance sheet became editable, correcting an evaluation's nature there left the progression, its
 * D/F/S counters and the Qualiopi export still stating the old one.
 *
 * So exactly ONE field is pushed back, and it is the one the rest of the app derives things from.
 * The title and the planned duration are deliberately NOT: a séance renamed or shortened for one
 * class is a decision about the library copy, while the progression line carries what was actually
 * planned - and its duration is what the placement arithmetic and the "x h placées / y h" totals are
 * built on. Overwriting those would move créneaux behind the teacher's back.
 */
class ProgressionSeanceSynchronizer
{
    public function __construct(
        private readonly ProgressionSeanceRepository $seanceRepository,
    ) {
    }

    /** @return int how many progression lines were updated - 0 when nothing planned this séance */
    public function syncEvaluationNature(SeanceInstance $seanceInstance): int
    {
        $updated = 0;

        foreach ($this->seanceRepository->findBySeanceInstance($seanceInstance) as $seance) {
            if ($seance->getEvaluationNature() === $seanceInstance->getEvaluationNature()) {
                continue;
            }

            $seance->setEvaluationNature($seanceInstance->getEvaluationNature());
            ++$updated;
        }

        return $updated;
    }
}
