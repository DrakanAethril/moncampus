<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Template;

use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateExhaustedException;
use App\SeriousGame\Template\TemplateFormatException;
use App\SeriousGame\Template\TemplateInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The template format `moncampus-gabarit/1` (design/validated/serious-games.md §5.2): what it
 * refuses, and how it draws.
 */
class TemplateInterpreterTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function template(array $overrides = []): array
    {
        return array_replace([
            'format' => 'moncampus-gabarit/1',
            'unit' => 'demo',
            'label' => 'Démo',
            'misconceptions' => [
                ['id' => 'demo-add', 'label' => 'Additionner'],
                ['id' => 'demo-sub', 'label' => 'Soustraire'],
                ['id' => 'demo-one', 'label' => 'Oublier un facteur'],
            ],
            'params' => ['a' => 'int(2, 9)', 'b' => 'int(2, 9)'],
            'constraints' => ['a != b'],
            'prompt' => 'Calculer {{ a }} × {{ b }}.',
            'answer' => ['type' => 'number', 'value' => 'a * b'],
            'wrongs' => [
                ['misconception' => 'demo-add', 'value' => 'a + b'],
                ['misconception' => 'demo-sub', 'value' => 'a - b'],
                ['misconception' => 'demo-one', 'value' => 'a'],
            ],
            'method' => '{{ a }} × {{ b }} = {{ a * b }}.',
        ], $overrides);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'no format' => [self::template(['format' => 'autre']), 'format'];
        yield 'unknown key' => [self::template(['couleur' => 'bleu']), 'couleur'];
        yield 'bad unit' => [self::template(['unit' => 'Démo !']), 'unit'];
        yield 'undeclared error' => [self::template(['wrongs' => [['misconception' => 'nope', 'value' => 'a']]]), 'wrongs[0].misconception'];
        yield 'unknown answer type' => [self::template(['answer' => ['type' => 'qcm', 'value' => 'a']]), 'answer.type'];
        yield 'polynomial without form' => [self::template(['answer' => ['type' => 'polynomial', 'value' => "'x'"]]), 'answer.form'];
        yield 'unreadable expression' => [self::template(['params' => ['a' => 'int(2, ']]), 'params.a'];
        yield 'unknown function' => [self::template(['let' => ['c' => 'sinus(a)']]), 'let.c'];
        yield 'reserved name' => [self::template(['let' => ['item' => '1']]), 'let.item'];
        yield 'duplicate error' => [self::template(['misconceptions' => [['id' => 'demo-add', 'label' => 'A'], ['id' => 'demo-add', 'label' => 'B']]]), 'misconceptions[1].id'];
        yield 'missing method' => [self::template(['method' => '']), 'method'];
        yield 'unknown figure item' => [self::template(['figure' => ['type' => 'grid', 'items' => [['draw' => 'circle']]]]), 'figure.items[0].draw'];
        yield 'formula without letters' => [self::template(['answer' => ['type' => 'formula', 'value' => "'U/I'"]]), 'answer'];
    }

    /** @param array<string, mixed> $document */
    #[DataProvider('refusals')]
    public function testADocumentIsRefusedWholeNamingWhere(array $document, string $path): void
    {
        try {
            (new TemplateInterpreter())->validate($document);
            self::fail('The document should have been refused.');
        } catch (TemplateFormatException $e) {
            self::assertSame($path, $e->path, $e->getMessage());
        }
    }

    public function testTheSameSeedGivesTheSameQuestion(): void
    {
        $interpreter = new TemplateInterpreter();
        $definition = new TemplateDefinition('demo', 1, self::template());

        $first = $interpreter->generate($definition, 42);
        $again = $interpreter->generate($definition, 42);

        self::assertSame($first->prompt, $again->prompt);
        self::assertSame($first->answerText, $again->answerText);
        self::assertSame($first->wrongs, $again->wrongs);
    }

    public function testSeedsGiveDifferentQuestions(): void
    {
        $interpreter = new TemplateInterpreter();
        $definition = new TemplateDefinition('demo', 1, self::template());

        $prompts = [];
        foreach (range(1, 40) as $seed) {
            $prompts[] = $interpreter->generate($definition, $seed)->prompt;
        }

        self::assertGreaterThan(20, \count(array_unique($prompts)));
    }

    public function testConstraintsDrawAgain(): void
    {
        $interpreter = new TemplateInterpreter();
        $definition = new TemplateDefinition('demo', 1, self::template());

        foreach (range(1, 50) as $seed) {
            $question = $interpreter->generate($definition, $seed);
            self::assertDoesNotMatchRegularExpression('/Calculer (\d) × \1\./', $question->prompt);
        }
    }

    public function testATemplateThatNeverHoldsIsExhausted(): void
    {
        $this->expectException(TemplateExhaustedException::class);

        (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, self::template(['constraints' => ['a > 100']])), 7);
    }

    public function testDrawsOnlyHappenInParams(): void
    {
        $this->expectException(TemplateFormatException::class);
        $this->expectExceptionMessageMatches('/params/');

        (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, self::template(['let' => ['c' => 'int(1, 3)']])), 7);
    }

    public function testNumbersAreWrittenTheFrenchWay(): void
    {
        $document = self::template([
            'params' => ['a' => 'int(3, 3)', 'b' => 'int(2, 2)'],
            'constraints' => [],
            'prompt' => '{{ -2.5 }} et {{ 12345 }}',
        ]);

        self::assertSame('−2,5 et 12'."\u{202F}".'345', (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, $document), 1)->prompt);
    }

    public function testWrongAnswersEqualToTheAnswerOrToEachOtherAreDropped(): void
    {
        $document = self::template([
            'wrongs' => [
                ['misconception' => 'demo-add', 'value' => 'a * b'],
                ['misconception' => 'demo-sub', 'value' => 'a + b'],
                ['misconception' => 'demo-one', 'value' => 'b + a'],
            ],
            'minWrongs' => 1,
        ]);

        $question = (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, $document), 3);

        self::assertSame(['demo-sub'], array_column($question->wrongs, 'misconception'));
    }

    public function testAWrongIsKeptOnlyWhenItsConditionHolds(): void
    {
        $document = self::template([
            'wrongs' => [
                ['misconception' => 'demo-add', 'value' => 'a + b', 'when' => 'a > b'],
                ['misconception' => 'demo-sub', 'value' => 'a - b + 100'],
            ],
            'minWrongs' => 1,
        ]);

        foreach (range(1, 20) as $seed) {
            $question = (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, $document), $seed);
            preg_match('/Calculer (\d) × (\d)/', $question->prompt, $m);
            self::assertSame((int) $m[1] > (int) $m[2], \in_array('demo-add', array_column($question->wrongs, 'misconception'), true));
        }
    }

    public function testVariantsAreDrawnByWeight(): void
    {
        $document = self::template([
            'params' => null,
            'constraints' => null,
            'prompt' => null,
            'answer' => null,
            'wrongs' => null,
            'method' => null,
            'minWrongs' => 1,
            'variants' => [
                ['weight' => 9, 'prompt' => 'Neuf', 'answer' => ['type' => 'number', 'value' => 9], 'wrongs' => [['misconception' => 'demo-add', 'value' => 1]], 'method' => 'm'],
                ['weight' => 1, 'prompt' => 'Un', 'answer' => ['type' => 'number', 'value' => 1], 'wrongs' => [['misconception' => 'demo-add', 'value' => 9]], 'method' => 'm'],
            ],
        ]);
        $document = array_filter($document, static fn (mixed $value): bool => null !== $value);

        $nine = 0;
        foreach (range(1, 400) as $seed) {
            $nine += 'Neuf' === (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, $document), $seed)->prompt ? 1 : 0;
        }

        self::assertGreaterThan(320, $nine);
        self::assertLessThan(395, $nine);
    }

    public function testAFigureIsDrawnOnTheServer(): void
    {
        $document = self::template(['figure' => ['type' => 'grid', 'x' => [-5, 5], 'y' => [-4, 5], 'items' => [
            ['draw' => 'line', 'm' => 'a', 'p' => '-b', 'color' => 'blue'],
            ['draw' => 'dot', 'x' => 1, 'y' => 'a - b', 'label' => "'A'"],
        ]]]);

        $question = (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, $document), 5);

        self::assertNotNull($question->figure);
        self::assertStringStartsWith('<svg class="sgm-fig"', $question->figure);
        self::assertStringContainsString('<circle', $question->figure);
    }

    public function testTheStatementGoesThroughTheSanitizer(): void
    {
        $interpreter = new TemplateInterpreter(sanitize: static fn (string $html): string => strtoupper($html));

        self::assertStringStartsWith('CALCULER', $interpreter->generate(new TemplateDefinition('demo', 1, self::template()), 5)->prompt);
    }

    public function testTheGameNeverReceivesTheAnswer(): void
    {
        $question = (new TemplateInterpreter())->generate(new TemplateDefinition('demo', 1, self::template()), 5);
        $view = $question->publicView();

        foreach (['answer', 'answerValue', 'answerText', 'wrongs', 'method'] as $key) {
            self::assertArrayNotHasKey($key, $view);
        }
        self::assertStringNotContainsString((string) $question->answerText, json_encode(array_diff_key($view, ['seed' => true]), \JSON_THROW_ON_ERROR));
    }

    public function testAQcmShowsTheAnswerAmongItsTypicalErrorsInTheOrderOfTheSeed(): void
    {
        $definition = new TemplateDefinition('qcm', 1, [
            'format' => 'moncampus-gabarit/1',
            'unit' => 'qcm',
            'label' => 'QCM',
            'misconceptions' => [['id' => 'q-a', 'label' => 'A'], ['id' => 'q-b', 'label' => 'B'], ['id' => 'q-c', 'label' => 'C'], ['id' => 'q-d', 'label' => 'D']],
            'params' => ['n' => 'int(2, 40)'],
            'prompt' => '{{ n }} + 1 ?',
            'answer' => ['type' => 'choice', 'value' => 'str(n + 1)'],
            'wrongs' => [
                ['misconception' => 'q-a', 'value' => 'str(n)'],
                ['misconception' => 'q-b', 'value' => 'str(n + 2)'],
                ['misconception' => 'q-c', 'value' => 'str(n - 1)'],
                ['misconception' => 'q-d', 'value' => 'str(n * 2)'],
            ],
            'method' => 'Ajouter 1.',
        ]);
        $interpreter = new TemplateInterpreter();
        $question = $interpreter->generate($definition, 7);

        self::assertSame('choice', $question->kind);
        self::assertCount(4, $question->choices);
        self::assertCount(3, $question->wrongs, 'only a proposition shown is an error one can make');
        self::assertContains($question->answerText, array_column($question->choices, 'value'));
        self::assertSame(array_column($question->choices, 'value'), array_column($interpreter->generate($definition, 7)->choices, 'value'));
        self::assertSame($question->choices, $question->publicView()['choices']);
    }

    public function testATableToFillNamesItsCells(): void
    {
        $definition = new TemplateDefinition('tab', 1, [
            'format' => 'moncampus-gabarit/1',
            'unit' => 'tab',
            'label' => 'Tableau',
            'misconceptions' => [['id' => 't-n', 'label' => 'Nombre']],
            'prompt' => 'Décline rosa au singulier.',
            'answer' => ['type' => 'forms', 'value' => "paradigmAlts(['rosa', 'rosae', 'f'], 'sg')", 'labels' => 'caseNames()'],
            'wrongs' => [['misconception' => 't-n', 'value' => "paradigm(['rosa', 'rosae', 'f'], 'pl')"]],
            'minWrongs' => 1,
            'method' => '{{ declTable([\'rosa\', \'rosae\', \'f\'], \'acc.sg\') }}',
        ]);
        $question = (new TemplateInterpreter())->generate($definition, 1);

        self::assertSame(['Nominatif', 'Vocatif', 'Accusatif', 'Génitif', 'Datif', 'Ablatif'], $question->publicView()['labels']);
        self::assertSame('rosa · rosa · rosam · rosae · rosae · rosa', $question->answerText);
        self::assertStringContainsString('<b><i>rosam</i></b>', $question->method);
    }
}
