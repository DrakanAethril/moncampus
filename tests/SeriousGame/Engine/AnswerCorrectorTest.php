<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Engine;

use App\SeriousGame\Engine\AnswerCorrector;
use App\SeriousGame\Engine\GeneratedQuestion;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateInterpreter;
use PHPUnit\Framework\TestCase;

/**
 * The correction of an answer (design/validated/serious-games.md §5.2): the shape asked is
 * signalled without counting wrong, a wrong value names its typical error, « je ne sais pas »
 * names none.
 */
class AnswerCorrectorTest extends TestCase
{
    public function testDontKnowIsNotRightAndNamesNoError(): void
    {
        $result = (new AnswerCorrector())->check($this->number(), AnswerCorrector::DONT_KNOW);

        self::assertFalse($result->ok);
        self::assertTrue($result->dontKnow);
        self::assertNull($result->misconception);
        self::assertTrue($result->isFinal());
    }

    public function testAWrongNumberNamesTheClosestTypicalError(): void
    {
        $result = (new AnswerCorrector())->check($this->number(), '7');

        self::assertFalse($result->ok);
        self::assertSame('demo-add', $result->misconception);
    }

    public function testAnUnknownWrongNumberNamesNothing(): void
    {
        $result = (new AnswerCorrector())->check($this->number(), '1000');

        self::assertFalse($result->ok);
        self::assertNull($result->misconception);
        self::assertTrue($result->isFinal());
    }

    public function testATextThatDoesNotReadIsAskedAgain(): void
    {
        $result = (new AnswerCorrector())->check($this->number(), 'douze');

        self::assertTrue($result->unreadable);
        self::assertFalse($result->isFinal());
    }

    public function testADevelopmentLeftInBracketsIsAskedAgainWithoutCountingWrong(): void
    {
        $question = $this->polynomial('developed', 'polyValue([2, 2])');

        $result = (new AnswerCorrector())->check($question, '2(x+1)');
        self::assertFalse($result->ok);
        self::assertSame(AnswerCorrector::FORM_DEVELOPED, $result->formIssue);
        self::assertFalse($result->isFinal());

        self::assertSame(AnswerCorrector::FORM_REDUCED, (new AnswerCorrector())->check($question, 'x + x + 2')->formIssue);
        self::assertTrue((new AnswerCorrector())->check($question, '2x + 2')->ok);
    }

    public function testAFactorisationMustBeAProduct(): void
    {
        $question = $this->polynomial('factored', "'(x+1)*(x-2)'");

        self::assertSame(AnswerCorrector::FORM_FACTORED, (new AnswerCorrector())->check($question, 'x²-x-2')->formIssue);
        self::assertTrue((new AnswerCorrector())->check($question, '(x+1)(x-2)')->ok);
    }

    public function testAFactorisationThatIsNotTheMostCompleteNamesItsError(): void
    {
        $question = $this->polynomial('factoredMax', "'2*x*(x+3)'", 'demo-shape');

        $result = (new AnswerCorrector())->check($question, '2(x²+3x)');
        self::assertFalse($result->ok);
        self::assertSame('demo-shape', $result->misconception);
        self::assertTrue((new AnswerCorrector())->check($question, '2x(x+3)')->ok);
    }

    public function testABadlyNormalisedScientificNotationNamesItsError(): void
    {
        $question = $this->generate([
            'answer' => ['type' => 'scientific', 'value' => 'sciNumber(3.2, -4)', 'display' => 'sci(3.2, -4)', 'text' => 'sciText(3.2, -4)', 'shapeMisconception' => 'demo-shape'],
            'wrongs' => [['misconception' => 'demo-add', 'value' => 'sciNumber(3.2, 4)', 'display' => 'sci(3.2, 4)']],
        ]);

        self::assertSame('demo-shape', (new AnswerCorrector())->check($question, '32 × 10^-5')->misconception);
        self::assertSame('demo-add', (new AnswerCorrector())->check($question, '3,2 × 10^4')->misconception);
        self::assertTrue((new AnswerCorrector())->check($question, '3,2e-4')->ok);
    }

    private function number(): GeneratedQuestion
    {
        return $this->generate([
            'answer' => ['type' => 'number', 'value' => '12'],
            'wrongs' => [
                ['misconception' => 'demo-add', 'value' => '7'],
                ['misconception' => 'demo-sub', 'value' => '1'],
            ],
        ]);
    }

    private function polynomial(string $form, string $value, ?string $shape = null): GeneratedQuestion
    {
        return $this->generate([
            'answer' => ['type' => 'polynomial', 'form' => $form, 'value' => $value] + (null === $shape ? [] : ['shapeMisconception' => $shape]),
            'wrongs' => [['misconception' => 'demo-add', 'value' => "'x + 100'"]],
        ]);
    }

    /** @param array<string, mixed> $body */
    private function generate(array $body): GeneratedQuestion
    {
        $document = [
            'format' => 'moncampus-gabarit/1',
            'unit' => 'demo',
            'label' => 'Démo',
            'misconceptions' => [['id' => 'demo-add', 'label' => 'A'], ['id' => 'demo-sub', 'label' => 'S'], ['id' => 'demo-shape', 'label' => 'F']],
            'prompt' => 'Question',
            'method' => 'Méthode',
            'minWrongs' => 1,
        ] + $body;

        return (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, $document), 1);
    }
}
