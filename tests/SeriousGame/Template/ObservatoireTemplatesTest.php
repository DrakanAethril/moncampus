<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Template;

use App\SeriousGame\Engine\FileTemplateSource;
use App\SeriousGame\SeriousGameManifest;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The 31 templates of L'Observatoire, shipped validated (design/validated/serious-games.md §5.3,
 * §5.4): each one passes the bench on 200 seeds - what CI runs; `app:serious-games:bench
 * observatoire --files` runs 1000.
 */
class ObservatoireTemplatesTest extends TestCase
{
    private const string DIRECTORY = __DIR__.'/../../../config/serious_games/observatoire';

    public function testEveryUnitOfTheGameHasItsTemplate(): void
    {
        $manifest = self::manifest();
        $files = (new FileTemplateSource(self::DIRECTORY.'/templates'))->units();
        $units = $manifest->unitCodes();
        sort($units);

        self::assertCount(31, $units);
        self::assertSame($units, $files);
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
