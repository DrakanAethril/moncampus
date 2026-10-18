<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Template;

use App\SeriousGame\Engine\FileTemplateSource;
use App\SeriousGame\SeriousGameManifest;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateDefinition;
use App\SeriousGame\Template\TemplateInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The 20 templates of The Irregulars, shipped validated (design/validated/serious-games-irregulars.md):
 * each one passes the bench on 200 seeds - what CI runs; `app:serious-games:bench irregulars --files`
 * runs 1000. Between them they serve the four kinds of answer the game accepts.
 */
class IrregularsTemplatesTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../../config/serious_games/irregulars';

    public function testEveryUnitOfTheGameHasItsTemplate(): void
    {
        $files = (new FileTemplateSource(self::DIRECTORY.'/templates'))->units();
        $units = self::manifest()->unitCodes();
        sort($units);

        self::assertCount(20, $units);
        self::assertSame($units, $files);
    }

    public function testATypicalErrorIsNamedInOneTemplateOnly(): void
    {
        $seen = [];
        foreach (self::manifest()->unitCodes() as $unit) {
            foreach (TemplateDefinition::fromFile(self::DIRECTORY.'/templates/'.$unit.'.json')->misconceptions() as $misconception) {
                self::assertArrayNotHasKey($misconception['id'], $seen, 'a false lead goes back to the one case that names its error');
                self::assertNotNull($misconception['explanation'], $misconception['id'].' opens its false lead with an explanation');
                $seen[$misconception['id']] = $unit;
            }
        }
    }

    public function testTheFinalCaseTakesItsStatementsFromTheFivePlaces(): void
    {
        $manifest = self::manifest();

        self::assertCount(5, $manifest->chapters());
        self::assertSame([3, 3, 2, 2, 2], $manifest->finalPerChapter());
        self::assertSame('fausse piste', $manifest->words()['comet']);
        self::assertSame('résolue', $manifest->words()['lit']);
    }

    public function testTheFourKindsOfAnswerAreServed(): void
    {
        $kinds = [];
        foreach (self::manifest()->unitCodes() as $unit) {
            foreach (TemplateDefinition::fromFile(self::DIRECTORY.'/templates/'.$unit.'.json')->kinds() as $kind) {
                $kinds[$kind] = true;
            }
        }
        ksort($kinds);

        self::assertSame(['choice', 'order', 'short', 'spot'], array_keys($kinds));
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
    }

    private static function manifest(): SeriousGameManifest
    {
        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile(self::DIRECTORY.'/game.yaml');

        return new SeriousGameManifest($data);
    }
}
