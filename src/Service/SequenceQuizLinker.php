<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;

/**
 * Attaching and detaching a quiz on the two levels of a séquence, and the asymmetry between them.
 *
 * A link is a *usage*, never an ownership: detaching never deletes anything, the quiz stays in the
 * teacher's library, which is its home (App\Entity\QuizTemplate::$seanceTemplates carries the
 * argument for the two relation tables).
 *
 * The one rule worth reading twice is the direction of the cascade, and it follows from what the
 * séquence's card shows. That card lists every quiz used anywhere in the séquence, séance-level ones
 * included and deduplicated (App\Service\SequenceQuizBoard) - so its « Détacher » speaks for the
 * whole séquence: **detaching at the séquence level detaches from its séances too**, otherwise the
 * quiz the teacher just removed would still be listed there, attached to a séance they never named.
 * The reverse is not true: detaching from a séance says nothing about the séquence, so a quiz
 * attached to both stays on the séquence and simply stops covering that séance.
 *
 * Nothing here flushes: the controller owns the transaction, as everywhere else in this repository.
 */
final class SequenceQuizLinker
{
    public function attachToSequence(QuizTemplate $quiz, SequenceTemplate $sequence): void
    {
        $quiz->addSequenceTemplate($sequence);
    }

    /**
     * The séquence's own « Détacher », which is the card's: it removes the séquence link **and** every
     * link this quiz has to a séance of that séquence. Other séquences are none of its business - a
     * réactivation quiz serving S2 of another séquence keeps that link.
     */
    public function detachFromSequence(QuizTemplate $quiz, SequenceTemplate $sequence): void
    {
        $quiz->removeSequenceTemplate($sequence);

        foreach ($sequence->getSeanceTemplates() as $seance) {
            $quiz->removeSeanceTemplate($seance);
        }
    }

    public function attachToSeance(QuizTemplate $quiz, SeanceTemplate $seance): void
    {
        $quiz->addSeanceTemplate($seance);
    }

    /** Only this séance: a quiz also attached to the séquence stays attached to it. */
    public function detachFromSeance(QuizTemplate $quiz, SeanceTemplate $seance): void
    {
        $quiz->removeSeanceTemplate($seance);
    }
}
