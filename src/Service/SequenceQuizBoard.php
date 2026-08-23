<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;

/**
 * The « Quiz de la séquence » card, and the one number it exists for: « 2 séances sur 4 ».
 *
 * That number is the whole reason QuizTemplate's two links are relation tables and not two nullable
 * foreign keys - it measures **usage**, which is multiple on both sides, and not provenance, which is
 * unique. QuizTemplate::$seanceTemplates carries the full argument.
 *
 * The card lists **one row per quiz**, whatever it is attached to: the séquence itself, one of its
 * séances, or both. A quiz used by three séances is one row naming three séances, not three rows -
 * deduplication is the point, a library existing precisely so the same quiz can serve twice. Each row
 * says where it hangs, which is what makes the séquence-level « Détacher » honest: it removes every
 * link the row shows (App\Service\SequenceQuizLinker).
 *
 * The rule this class owns is the subtraction: **coverage counts séances, and séquence-level quizzes
 * count for none of them.** The Ansible kit's final QCM is attached to the whole séquence; letting it
 * count would report four covered séances for a séquence whose séances carry no questions at all -
 * which is the exact lie the card was built to prevent.
 *
 * No repository and no query: it walks the collections the controller already loaded, so the card costs
 * whatever the fetch-join costs and nothing per séance.
 *
 * There is deliberately no **Lancer** button anywhere on this card. A launch needs a Program, so it
 * lives on the séquence *instantiated* into a formation, which finds the same quizzes back through
 * SeanceInstance::$sourceTemplate.
 *
 * @phpstan-type SequenceQuizSeanceRef array{id: ?int, titre: string, ordre: int}
 * @phpstan-type SequenceQuizRow array{quiz: QuizTemplate, onSequence: bool, seances: list<SequenceQuizSeanceRef>}
 * @phpstan-type SequenceQuizBoardData array{quizzes: list<SequenceQuizRow>, coveredSeances: int, totalSeances: int, hasAnyQuiz: bool}
 * @phpstan-type SeanceQuizRow array{quiz: QuizTemplate, onSequence: bool}
 * @phpstan-type SeanceQuizBoardData array{quizzes: list<SeanceQuizRow>}
 */
final class SequenceQuizBoard
{
    /**
     * @return SequenceQuizBoardData
     */
    public function forSequence(SequenceTemplate $sequence): array
    {
        /** @var array<int, SequenceQuizRow> $rows keyed by object id, so a quiz met twice is found again */
        $rows = [];

        foreach ($sequence->getQuizTemplates() as $quiz) {
            $rows[spl_object_id($quiz)] = ['quiz' => $quiz, 'onSequence' => true, 'seances' => []];
        }

        $covered = 0;
        $totalSeances = 0;
        foreach ($sequence->getSeanceTemplates() as $seance) {
            ++$totalSeances;
            $reference = [
                'id' => $seance->getId(),
                'titre' => (string) $seance->getTitre(),
                'ordre' => $seance->getOrdre(),
            ];

            $carries = false;
            foreach ($seance->getQuizTemplates() as $quiz) {
                $carries = true;
                $key = spl_object_id($quiz);
                $rows[$key] ??= ['quiz' => $quiz, 'onSequence' => false, 'seances' => []];
                $rows[$key]['seances'][] = $reference;
            }

            if ($carries) {
                // Once per séance, however many quizzes it carries: a séance holding a diagnostic and a
                // final is one covered séance, not two.
                ++$covered;
            }
        }

        return [
            'quizzes' => array_values($rows),
            'coveredSeances' => $covered,
            'totalSeances' => $totalSeances,
            'hasAnyQuiz' => [] !== $rows,
        ];
    }

    /**
     * The same card on a séance, which is a shorter question: this séance's quizzes, each saying
     * whether the séquence names it too - because that is exactly what the séance's « Détacher »
     * leaves behind.
     *
     * @return SeanceQuizBoardData
     */
    public function forSeance(SeanceTemplate $seance): array
    {
        $sequence = $seance->getSequenceTemplate();

        $quizzes = [];
        foreach ($seance->getQuizTemplates() as $quiz) {
            $quizzes[] = [
                'quiz' => $quiz,
                'onSequence' => $quiz->getSequenceTemplates()->contains($sequence),
            ];
        }

        return ['quizzes' => $quizzes];
    }

    /**
     * Named after a séance or after the séquence, for the pre-checked destination the quiz import
     * offers on its way back (« rattacher à la séance … »).
     */
    public function isCoveredBy(SeanceTemplate $seance, QuizTemplate $quiz): bool
    {
        return $seance->getQuizTemplates()->contains($quiz);
    }
}
