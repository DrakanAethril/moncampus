<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\QuizQuestionDefinition;
use App\Service\QuizAttemptGrader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets the correction screens (1m, and the teacher's "Voir la copie") colour each blank of a texte
 * à trous green or red, from the same grader the score was computed with.
 *
 * Recomputing rather than storing the per-blank verdicts is safe here, unlike the question-level
 * grade: both inputs are already frozen (the QuizInstanceQuestion is a launch-time copy, the
 * responses are the ones the student submitted), so this can only ever reproduce the verdict that
 * produced QuizAttemptAnswer::$score. Storing a second copy would just be a way for the two to
 * disagree.
 */
class QuizBlanksExtension extends AbstractExtension
{
    public function __construct(private readonly QuizAttemptGrader $grader)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('blank_results', $this->blankResults(...)),
            new TwigFunction('zone_results', $this->zoneResults(...)),
        ];
    }

    /**
     * @param list<string> $responses
     *
     * @return list<bool>
     */
    public function blankResults(QuizQuestionDefinition $question, array $responses): array
    {
        return $this->grader->blankResults($question, $responses);
    }

    /**
     * Same contract for a Légende question, keyed by zone id.
     *
     * @param array<array-key, string> $placements
     *
     * @return array<string, bool>
     */
    public function zoneResults(QuizQuestionDefinition $question, array $placements): array
    {
        return $this->grader->zoneResults($question, $placements);
    }
}
