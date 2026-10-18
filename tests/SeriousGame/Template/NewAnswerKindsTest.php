<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Template;

use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateInterpreter;
use PHPUnit\Framework\TestCase;

/**
 * The answers and figures Station Abysse and The Irregulars brought to the format: items to put in
 * order, a word to point at, a strand to type, and the chart, food web and tree of kinship - each
 * one through the bench, which is what a shipped template has to pass.
 */
class NewAnswerKindsTest extends TestCase
{
    public function testItemsToPutInOrderAreShuffledAndPassTheBench(): void
    {
        $definition = self::definition([
            'params' => ['picked' => "sample([['Algues', 0], ['Krill', 1], ['Poisson-lanterne', 2], ['Calmar', 3], ['Cachalot', 4], ['Copépodes', 1], ['Baudroie', 3], ['Phytoplancton', 0]], 4)"],
            'let' => [
                'web' => "{'levels': {'a': 0, 'c': 1, 'd': 2, 'e': 3}, 'names': {'a': 'Algues', 'c': 'Oursin', 'd': 'Baliste', 'e': 'Requin'}, 'edges': [['a', 'c'], ['c', 'd'], ['d', 'e']]}",
                'all' => "map(sortBy(picked, 'item[1]'), 'item[0]')",
            ],
            'constraints' => ["count(unique(map(picked, 'item[1]'))) == 4"],
            'prompt' => 'Range du producteur au dernier consommateur.',
            'figure' => ['type' => 'web', 'levels' => "web['levels']", 'names' => "web['names']", 'edges' => "web['edges']"],
            'answer' => ['type' => 'order', 'value' => 'all'],
            'wrongs' => [['misconception' => 'x-one', 'value' => 'reversed(all)']],
            'minWrongs' => 1,
            'method' => '{{ join(all, " → ") }}',
        ]);
        $question = (new TemplateInterpreter())->generate($definition, 7);

        self::assertSame('order', $question->kind);
        self::assertCount(4, $question->choices);
        self::assertNotSame($question->answerValue, array_column($question->choices, 'value'));
        self::assertStringContainsString('Réseau trophique', (string) $question->figure);
        self::assertBenchGreen($definition);
    }

    public function testAWordToPointAtPassesTheBench(): void
    {
        $definition = self::definition([
            'params' => ['wrong' => 'chance(0.6)', 'v' => "pick([['see', 'saw'], ['take', 'took'], ['find', 'found'], ['hide', 'hid'], ['steal', 'stole'], ['bring', 'brought'], ['buy', 'bought'], ['lose', 'lost']])", 'o' => "pick(['the bag', 'the key', 'the letter', 'the watch'])"],
            'let' => [
                'line' => "'He didn’t ' ~ (wrong ? v[1] : v[0]) ~ ' ' ~ o ~ '.'",
                'tokens' => 'words(line)',
            ],
            'prompt' => '{{ line }}',
            'answer' => ['type' => 'spot', 'value' => 'wrong ? 2 : -1', 'labels' => 'tokens'],
            'wrongs' => [
                ['misconception' => 'x-one', 'value' => '-1', 'when' => 'wrong'],
                ['misconception' => 'x-two', 'value' => '-2'],
            ],
            'minWrongs' => 1,
            'method' => 'After didn’t, the base form.',
        ]);

        self::assertBenchGreen($definition);
    }

    public function testAStrandAChartAndATreeOfKinship(): void
    {
        $definition = self::definition([
            'params' => ['s' => 'dna(9)', 'o0' => 'int(70, 90) / 10', 'noise' => "map(range(0, 20), 'gauss()')"],
            'let' => ['c' => 'complement(s)', 'pts' => "map(range(0, 20), '[item, o0 - item * 0.25 + 0.08 * noise[item]]')"],
            'prompt' => 'Complète {{ seq(s) }}',
            'figure' => ['type' => 'chart', 'x' => [0, 20], 'y' => [0, 10], 'xstep' => 5, 'ystep' => 2, 'xlabel' => "'Temps (min)'", 'ylabel' => "'O₂ (mg/L)'", 'series' => "[{'name': 'Dioxygène', 'color': 'blue', 'points': pts}]"],
            'answer' => ['type' => 'sequence', 'value' => 'c', 'display' => 'seq(c)'],
            'wrongs' => [['misconception' => 'x-one', 'value' => 's'], ['misconception' => 'x-two', 'value' => "replace(c, 'T', 'U')", 'when' => "c matches '/T/' ? true : false"]],
            'minWrongs' => 1,
            'method' => 'A avec T, C avec G.',
        ]);
        self::assertStringContainsString('polyline', (string) (new TemplateInterpreter())->generate($definition, 3)->figure);
        self::assertBenchGreen($definition);

        $tree = self::definition([
            'prompt' => 'Qui est le plus proche parent du thon ?',
            'figure' => ['type' => 'cladogram', 'leaves' => "['Pieuvre', 'Requin', 'Thon', 'Hippocampe']", 'first' => "['Mâchoires']", 'second' => "['Nageoires rayonnées']"],
            'answer' => ['type' => 'choice', 'value' => "'Hippocampe'", 'choices' => 3],
            'wrongs' => [['misconception' => 'x-one', 'value' => "'Requin'"], ['misconception' => 'x-two', 'value' => "'Pieuvre'"]],
            'minWrongs' => 2,
            'method' => 'Nœud en nœud.',
        ]);
        self::assertStringContainsString('Arbre de parenté', (string) (new TemplateInterpreter())->generate($tree, 1)->figure);
    }

    private static function assertBenchGreen(TemplateDefinition $definition): void
    {
        $report = (new TemplateBench(new TemplateInterpreter()))->run($definition, 120);
        self::assertTrue($report['green'], json_encode($report['failures'], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '');
    }

    /** @param array<string, mixed> $body */
    private static function definition(array $body): TemplateDefinition
    {
        return new TemplateDefinition('demo', 1, [
            'format' => 'moncampus-gabarit/1',
            'unit' => 'demo',
            'label' => 'Démo',
            'misconceptions' => [['id' => 'x-one', 'label' => 'Une'], ['id' => 'x-two', 'label' => 'Deux']],
        ] + $body);
    }
}
