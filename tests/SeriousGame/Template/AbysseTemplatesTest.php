<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Template;

use App\SeriousGame\Engine\FileTemplateSource;
use App\SeriousGame\Engine\QuestionEngine;
use App\SeriousGame\SeriousGameManifest;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The 30 templates of Station Abysse, shipped validated (design/validated/serious-games-abysse.md):
 * each one passes the bench on 200 seeds - what CI runs; `app:serious-games:bench abysse --files`
 * runs 1000 - and asks its questions at the dive's three depths, so that a series climbs from
 * 200 m to 3 000 m.
 */
class AbysseTemplatesTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../../config/serious_games/abysse';

    public function testEveryUnitOfTheGameHasItsTemplate(): void
    {
        $files = (new FileTemplateSource(self::DIRECTORY.'/templates'))->units();
        $units = self::manifest()->unitCodes();
        sort($units);

        self::assertCount(30, $units);
        self::assertSame($units, $files);
    }

    public function testATypicalErrorIsNamedInOneTemplateOnly(): void
    {
        $seen = [];
        foreach (self::manifest()->unitCodes() as $unit) {
            foreach (TemplateDefinition::fromFile(self::DIRECTORY.'/templates/'.$unit.'.json')->misconceptions() as $misconception) {
                self::assertArrayNotHasKey($misconception['id'], $seen, 'an anomaly goes back to the one species that names its error');
                self::assertStringStartsWith($unit.'-', $misconception['id']);
                self::assertNotNull($misconception['explanation'], $misconception['id'].' opens its anomaly with an explanation');
                $seen[$misconception['id']] = $unit;
            }
        }
    }

    public function testTheAscentTakesItsShareFromEachZoneAndTheDiveClimbs(): void
    {
        $manifest = self::manifest();

        self::assertCount(5, $manifest->chapters());
        self::assertSame([3, 3, 2, 2, 2], $manifest->finalPerChapter());
        self::assertSame([4, 4, 4], $manifest->seriesTiers());
        self::assertSame('anomalie', $manifest->words()['comet']);
        self::assertSame('cataloguée', $manifest->words()['lit']);
    }

    /** @return iterable<string, array{string}> */
    public static function units(): iterable
    {
        foreach (self::manifest()->unitCodes() as $unit) {
            yield $unit => [$unit];
        }
    }

    #[DataProvider('units')]
    public function testTheTemplatePassesTheBench(string $unit): void
    {
        $definition = (new FileTemplateSource(self::DIRECTORY.'/templates'))->current($unit);
        self::assertNotNull($definition, $unit);
        self::assertSame($unit, $definition->unit);

        $report = (new TemplateBench(new TemplateInterpreter()))->run($definition, 200);

        self::assertTrue($report['green'], $unit.' : '.json_encode($report['failures'], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));
        self::assertSame($report['wrongs'], $report['recognised']);
        self::assertSame([], $report['neverSeen'], $unit.' : every typical error is recognised by some question');
    }

    #[DataProvider('units')]
    public function testTheTemplateDivesToTheThreeDepths(string $unit): void
    {
        $definition = (new FileTemplateSource(self::DIRECTORY.'/templates'))->current($unit);
        self::assertNotNull($definition, $unit);
        $interpreter = new TemplateInterpreter();

        $levels = [];
        foreach (TemplateBench::seeds(200) as $seed) {
            $levels[$interpreter->generate($definition, $seed)->difficulty] = true;
        }
        ksort($levels);
        self::assertSame([1, 2, 3], array_keys($levels), $unit);

        $engine = new QuestionEngine($interpreter);
        $plan = $engine->seriesPlan($definition, 12, ['short', 'choice', 'order'], [4, 4, 4]);
        $climb = array_map(static fn (array $planned): int => $engine->generate($definition, $planned['seed'])->difficulty, $plan);
        self::assertSame([1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3], $climb, $unit);
    }

    private static function manifest(): SeriousGameManifest
    {
        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile(self::DIRECTORY.'/game.yaml');

        return new SeriousGameManifest($data);
    }
}
