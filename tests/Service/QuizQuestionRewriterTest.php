<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionTimeMode;
use App\Enum\QuestionType;
use App\Service\QuizQuestionRewriter;
use PHPUnit\Framework\TestCase;

/**
 * A question rewritten from a document keeps what it has - its quiz, its position, its image - and
 * takes everything it is from the document, answers included.
 */
final class QuizQuestionRewriterTest extends TestCase
{
    public function testTheQuestionTakesTheSourceContentAndKeepsItsPlaceAndImage(): void
    {
        $quiz = new QuizTemplate(new User('teacher'));
        $target = $this->question($quiz, QuestionType::Qcm, 'Ancien énoncé', ['A' => true, 'B' => false]);
        $target->setOrderIndex(4);
        $target->attachMedia('quiz-question-images/old.png');

        $source = $this->question(new QuizTemplate(new User('scratch')), QuestionType::Image, 'Que montre ce schéma ?', ['Un switch' => false, 'Un routeur' => true, 'Un hub' => false]);
        $source->setDifficulty(QuestionDifficulty::Difficile);
        $source->setExplanation('Il relie deux réseaux.');
        $source->setPoints(2.0);
        $source->setTimeMode(QuestionTimeMode::Fixed);
        $source->setTimeSeconds(45);
        $source->setExpectedMediaName('schema.png');

        (new QuizQuestionRewriter())->rewrite($target, $source);

        self::assertSame($quiz, $target->getQuizTemplate());
        self::assertSame(4, $target->getOrderIndex());
        self::assertSame('quiz-question-images/old.png', $target->getImageStorageKey());
        self::assertNull($target->getExpectedMediaName(), 'the image it has is not waited for');
        self::assertSame(QuestionType::Image, $target->getType());
        self::assertSame('Que montre ce schéma ?', $target->getLabel());
        self::assertSame('Il relie deux réseaux.', $target->getExplanation());
        self::assertSame(2.0, $target->getPoints());
        self::assertSame(QuestionTimeMode::Fixed, $target->getTimeMode());
        self::assertSame(45, $target->getTimeSeconds());
        self::assertSame(
            [['Un switch', false], ['Un routeur', true], ['Un hub', false]],
            array_map(static fn (QuizAnswer $a): array => [$a->getLabel(), $a->isCorrect()], array_values($target->getAnswers()->toArray())),
        );
        foreach ($target->getAnswers() as $answer) {
            self::assertSame($target, $answer->getQuestion());
        }
    }

    public function testAQuestionWithoutImageWaitsForTheMediaTheSourceNames(): void
    {
        $target = $this->question(new QuizTemplate(new User('teacher')), QuestionType::Qcm, 'Énoncé', []);
        $source = $this->question(new QuizTemplate(new User('scratch')), QuestionType::Image, 'Énoncé', []);
        $source->setExpectedMediaName('carte.jpg');

        (new QuizQuestionRewriter())->rewrite($target, $source);

        self::assertSame('carte.jpg', $target->getExpectedMediaName());
    }

    /** @param array<string, bool> $answers */
    private function question(QuizTemplate $quiz, QuestionType $type, string $label, array $answers): QuizQuestion
    {
        $question = (new QuizQuestion($quiz))->setType($type)->setLabel($label);
        $rank = 0;
        foreach ($answers as $text => $correct) {
            $question->addAnswer((new QuizAnswer($question))->setLabel($text)->setIsCorrect($correct)->setOrderIndex(++$rank));
        }

        return $question;
    }
}
