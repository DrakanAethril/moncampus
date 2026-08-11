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
            new TwigFunction('matching_results', $this->matchingResults(...)),
            new TwigFunction('numeric_expected', $this->numericExpected(...)),
            new TwigFunction('numeric_margin', $this->numericMargin(...)),
            new TwigFunction('numeric_display_variables', $this->numericDisplayVariables(...)),
        ];
    }

    /**
     * What the question expected of this student - the teacher's value, or their own formula
     * result. Same reasoning as the results above: both inputs are frozen (the instance question is
     * a launch-time copy, the drawn values are stored on the answer), so recomputing here can only
     * reproduce the verdict that produced the score.
     *
     * @param array<string, float> $variables
     */
    public function numericExpected(QuizQuestionDefinition $question, array $variables = []): ?float
    {
        return $this->grader->expectedNumericValue($question, $variables);
    }

    /** @param array<string, float> $variables */
    public function numericMargin(QuizQuestionDefinition $question, array $variables = []): ?float
    {
        return $this->grader->numericMargin($question, $variables);
    }

    /**
     * The drawn values as the statement should read them - each rounded to its own variable's
     * decimals and written the French way, so "{v}" prints "1 200" and not "1200.0".
     *
     * @param array<string, float> $variables
     *
     * @return array<string, string>
     */
    public function numericDisplayVariables(QuizQuestionDefinition $question, array $variables = []): array
    {
        $decimals = [];
        foreach ($question->getNumericVariables() as $variable) {
            $decimals[$variable['name']] = $variable['decimals'];
        }

        $formatted = [];
        foreach ($variables as $name => $value) {
            $formatted[$name] = number_format($value, $decimals[$name] ?? 0, ',', ' ');
        }

        return $formatted;
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

    /**
     * Same contract again for an Apparier question, keyed by pair id.
     *
     * @param array<array-key, string> $associations
     *
     * @return array<string, bool>
     */
    public function matchingResults(QuizQuestionDefinition $question, array $associations): array
    {
        return $this->grader->matchingResults($question, $associations);
    }
}
