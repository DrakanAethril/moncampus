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
 * @phpstan-type SequenceQuizSeanceRow array{id: ?int, titre: string, ordre: int, quizzes: list<QuizTemplate>}
 * @phpstan-type SequenceQuizBoardData array{sequenceQuizzes: list<QuizTemplate>, seances: list<SequenceQuizSeanceRow>, coveredSeances: int, totalSeances: int, hasAnyQuiz: bool}
 */
final class SequenceQuizBoard
{
    /**
     * @return SequenceQuizBoardData
     */
    public function forSequence(SequenceTemplate $sequence): array
    {
        $sequenceQuizzes = $this->quizzesOf($sequence->getQuizTemplates()->toArray());

        $seances = [];
        $covered = 0;
        foreach ($sequence->getSeanceTemplates() as $seance) {
            $quizzes = $this->quizzesOf($seance->getQuizTemplates()->toArray());
            if ([] !== $quizzes) {
                // Once per séance, however many quizzes it carries: a séance holding a diagnostic and a
                // final is one covered séance, not two.
                ++$covered;
            }

            $seances[] = [
                'id' => $seance->getId(),
                'titre' => (string) $seance->getTitre(),
                'ordre' => $seance->getOrdre(),
                'quizzes' => $quizzes,
            ];
        }

        return [
            'sequenceQuizzes' => $sequenceQuizzes,
            'seances' => $seances,
            'coveredSeances' => $covered,
            'totalSeances' => \count($seances),
            'hasAnyQuiz' => [] !== $sequenceQuizzes || $covered > 0,
        ];
    }

    /**
     * Named after a séance or after the séquence, for the pre-checked destination the quiz import
     * offers on its way back (« rattacher à la séance … »).
     */
    public function isCoveredBy(SeanceTemplate $seance, QuizTemplate $quiz): bool
    {
        return $seance->getQuizTemplates()->contains($quiz);
    }

    /**
     * @param array<array-key, QuizTemplate> $quizzes
     *
     * @return list<QuizTemplate>
     */
    private function quizzesOf(array $quizzes): array
    {
        return array_values($quizzes);
    }
}
