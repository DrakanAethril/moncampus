<?php

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\SeanceInstance;
use App\Repository\ProgressionSeancePlacementRepository;
use App\Repository\SeanceInstanceRepository;

/**
 * "Which séance is taught on this créneau?" - the single question the lesson log's "pré-remplir"
 * needs answered, asked in one place instead of once per call site.
 *
 * Two routes to the same answer, in order:
 *  1. SeanceInstance::$lessonSession, the direct link App\Service\ProgressionPlacementService
 *     writes on validation. It is a unique OneToOne, so it names at most ONE créneau per séance.
 *  2. Failing that, the progression's own placements. This is what covers the créneaux the link
 *     structurally cannot reach: a séance duplicated once per group, or split over two créneaux,
 *     occupies several - and each of them teaches that same séance, so each deserves the same
 *     starting content. Before this, only the first one offered "pré-remplir" and the others were
 *     filled by hand for no reason a teacher could see.
 */
class SeanceContentResolver
{
    public function __construct(
        private readonly SeanceInstanceRepository $seanceInstanceRepository,
        private readonly ProgressionSeancePlacementRepository $placementRepository,
    ) {
    }

    public function forLessonSession(LessonSession $session): ?SeanceInstance
    {
        $direct = $this->seanceInstanceRepository->findOneByLessonSession($session);
        if (null !== $direct) {
            return $direct;
        }

        return $this->placementRepository->findOneByLessonSession($session)
            ?->getProgressionSeance()
            ?->getSeanceInstance();
    }
}
