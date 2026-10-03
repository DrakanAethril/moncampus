<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Service\QuizQuestionTakeView;
use PHPUnit\Framework\TestCase;

/**
 * The answer rows a take screen draws: in their stored order unless asked otherwise - a course's
 * test asks, so that a reader coming back cannot answer by position.
 */
class QuizQuestionTakeViewTest extends TestCase
{
    public function testAnswersKeepTheirOrderUnlessAShuffleIsAsked(): void
    {
        $question = $this->question(QuestionType::Qcm, ['A', 'B', 'C', 'D']);

        self::assertSame(['A', 'B', 'C', 'D'], $this->labels(QuizQuestionTakeView::of($question, [])));

        $orders = [];
        for ($draw = 0; $draw < 40; ++$draw) {
            $orders[implode('', $this->labels(QuizQuestionTakeView::of($question, [], shuffleAnswers: true)))] = true;
        }
        self::assertGreaterThan(1, \count($orders));
    }

    public function testVraiAndFauxStayInPlace(): void
    {
        $question = $this->question(QuestionType::VraiFaux, ['Vrai', 'Faux']);

        for ($draw = 0; $draw < 20; ++$draw) {
            self::assertSame(['Vrai', 'Faux'], $this->labels(QuizQuestionTakeView::of($question, [], shuffleAnswers: true)));
        }
    }

    /**
     * @param list<string> $labels
     */
    private function question(QuestionType $type, array $labels): QuizQuestion
    {
        $question = new QuizQuestion(new QuizTemplate(new User('prof')));
        $question->setType($type);
        foreach ($labels as $label) {
            $answer = new QuizAnswer($question);
            $answer->setLabel($label);
            $question->addAnswer($answer);
        }

        return $question;
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return list<string>
     */
    private function labels(array $view): array
    {
        $answers = $view['answers'];
        self::assertIsArray($answers);

        return array_values(array_map(static fn (mixed $answer): string => $answer instanceof QuizAnswer ? (string) $answer->getLabel() : '', $answers));
    }
}
