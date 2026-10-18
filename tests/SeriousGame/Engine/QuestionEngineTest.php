<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Engine;

use App\SeriousGame\Engine\AnswerCorrector;
use App\SeriousGame\Engine\QuestionEngine;
use App\SeriousGame\Engine\TemplateSource;
use App\SeriousGame\SeriousGameManifest;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateInterpreter;
use PHPUnit\Framework\TestCase;

/**
 * The sessions' questions (design/validated/serious-games.md §5.5): a series holds no question
 * twice, the final test is 3 + 3 + 2 + 2 + 2 distinct units, a comet's questions can show its error,
 * and a unit of an answer kind the game does not accept is hidden.
 */
class QuestionEngineTest extends TestCase
{
    public function testASeriesHoldsTwelveDistinctQuestions(): void
    {
        $engine = $this->engine();
        $plan = $engine->seriesPlan($this->definition('u1'), 12);

        self::assertCount(12, $plan);
        $identities = array_map(fn (array $p): string => $engine->generate($this->definition('u1'), $p['seed'])->identity(), $plan);
        self::assertCount(12, array_unique($identities));
        self::assertSame(['u1'], array_values(array_unique(array_column($plan, 'unit'))));
    }

    public function testTheFinalTestTakesItsShareFromEachChapter(): void
    {
        $manifest = $this->manifest();
        $plan = $this->engine()->finalPlan($manifest, $this->source(), $manifest->unitCodes());

        self::assertCount(12, $plan);
        self::assertCount(12, array_unique(array_column($plan, 'unit')));
        $perChapter = [];
        foreach ($plan as $question) {
            $chapter = $manifest->unit($question['unit'])['chapter'] ?? '?';
            $perChapter[$chapter] = ($perChapter[$chapter] ?? 0) + 1;
        }
        $counts = array_values($perChapter);
        sort($counts);
        self::assertSame([2, 2, 2, 3, 3], $counts);
    }

    public function testACometsQuestionsCanShowItsError(): void
    {
        $engine = $this->engine();
        $definition = $this->definition('u1');
        $plan = $engine->cometPlan($definition, 'e-rare', 3);

        self::assertCount(3, $plan);
        foreach ($plan as $question) {
            self::assertContains('e-rare', array_column($engine->generate($definition, $question['seed'])->wrongs, 'misconception'));
        }
    }

    public function testAUnitOfAnotherAnswerKindIsHidden(): void
    {
        $source = new class implements TemplateSource {
            public function current(string $unit): ?TemplateDefinition
            {
                return 'u2' === $unit ? null : new TemplateDefinition($unit, 1, ['kind' => 'u3' === $unit ? 'choice' : 'short']);
            }

            public function version(string $unit, int $version): ?TemplateDefinition
            {
                return null;
            }
        };

        $servable = $this->engine()->servableUnits(new SeriousGameManifest(['chapters' => [['code' => 'c', 'units' => [['code' => 'u1'], ['code' => 'u2'], ['code' => 'u3']]]]]), $source, ['short']);

        self::assertSame(['u1'], $servable);
    }

    public function testTheCometOpensOnTheErrorNamedThenTheMethod(): void
    {
        $engine = $this->engine();
        $definition = $this->definition('u1');
        $example = $engine->generate($definition, 1);

        self::assertSame('L’erreur qui revient : additionner. '.$example->method, $engine->cometIntroduction($definition, 'e-add', $example));
    }

    private function engine(): QuestionEngine
    {
        $seed = 0;

        return new QuestionEngine(new TemplateInterpreter(), new AnswerCorrector(), static function () use (&$seed): int {
            return ++$seed * 7919;
        });
    }

    private function definition(string $unit): TemplateDefinition
    {
        return new TemplateDefinition($unit, 1, [
            'format' => 'moncampus-gabarit/1',
            'unit' => $unit,
            'label' => 'Unité',
            'misconceptions' => [['id' => 'e-add', 'label' => 'Additionner'], ['id' => 'e-sub', 'label' => 'Soustraire'], ['id' => 'e-rare', 'label' => 'Rare']],
            'params' => ['a' => 'int(2, 30)', 'b' => 'int(2, 30)'],
            'prompt' => '{{ a }} × {{ b }}',
            'answer' => ['type' => 'number', 'value' => 'a * b'],
            'wrongs' => [
                ['misconception' => 'e-add', 'value' => 'a + b'],
                ['misconception' => 'e-sub', 'value' => 'a - b'],
                ['misconception' => 'e-rare', 'value' => 'a * b + 1', 'when' => 'a > 25'],
            ],
            'minWrongs' => 1,
            'method' => '{{ a }} × {{ b }} = {{ a * b }}.',
        ]);
    }

    private function manifest(): SeriousGameManifest
    {
        $chapters = [];
        foreach (['c1' => 7, 'c2' => 6, 'c3' => 5, 'c4' => 7, 'c5' => 6] as $code => $size) {
            $chapters[] = ['code' => $code, 'units' => array_map(static fn (int $i): array => ['code' => $code.'-'.$i], range(1, $size))];
        }

        return new SeriousGameManifest(['chapters' => $chapters, 'final' => ['per_chapter' => [3, 3, 2, 2, 2]]]);
    }

    private function source(): TemplateSource
    {
        $test = $this;

        return new class($test) implements TemplateSource {
            public function __construct(private readonly QuestionEngineTest $test)
            {
            }

            public function current(string $unit): TemplateDefinition
            {
                return $this->test->definitionFor($unit);
            }

            public function version(string $unit, int $version): ?TemplateDefinition
            {
                return null;
            }
        };
    }

    public function definitionFor(string $unit): TemplateDefinition
    {
        return $this->definition($unit);
    }
}
