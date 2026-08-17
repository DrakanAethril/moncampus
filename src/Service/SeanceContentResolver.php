<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\SeanceInstance;
use App\Enum\LessonLogSection;
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

    /**
     * What the séance already says for each of the three parts of a cahier de texte - the text a
     * teacher opening the créneau should not have to type again.
     *
     * The « pendant » part falls back on the objectives when no trace was written for the students:
     * coarser, but better than an empty field. A part the séance says nothing about is absent from
     * the map rather than present and empty, so a caller only has to test the key.
     *
     * @return array<string, string> keyed by LessonLogSection::value
     */
    public function defaultsFor(?SeanceInstance $seance): array
    {
        if (null === $seance) {
            return [];
        }

        $candidates = [
            LessonLogSection::Before->value => $seance->getAvantDescription(),
            LessonLogSection::During->value => $seance->getCahierDeTexteDescription() ?: $seance->getObjectifs(),
            LessonLogSection::After->value => $seance->getApresDescription(),
        ];

        $defaults = [];
        foreach ($candidates as $section => $content) {
            if (self::saysSomething($content)) {
                $defaults[$section] = (string) $content;
            }
        }

        return $defaults;
    }

    /**
     * A rich-text field is empty as soon as it carries no words: HugeRTE leaves a `<p><br></p>`
     * behind when the teacher clears it, and that is not content.
     */
    public static function saysSomething(?string $content): bool
    {
        return '' !== trim(strip_tags(str_replace('&nbsp;', ' ', (string) $content)));
    }
}
