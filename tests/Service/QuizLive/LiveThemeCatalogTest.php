<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizLive;

use App\Service\QuizLive\LiveTheme;
use App\Service\QuizLive\LiveThemeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The live contest's themes (config/quiz_live/themes.yaml): a theme is its entry and its stylesheet,
 * and this test is what makes adding one those two files and nothing else - it reads the shipped
 * catalogue strictly and looks for each theme's stylesheet, scoped under its own slug.
 */
class LiveThemeCatalogTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private function catalog(): LiveThemeCatalog
    {
        return new LiveThemeCatalog(self::ROOT.'/config/quiz_live/themes.yaml');
    }

    public function testTheShippedCatalogueReadsWithPopAsTheDefault(): void
    {
        $catalog = $this->catalog();

        self::assertSame('pop', $catalog->default()->slug);
        self::assertSame(['pop', 'plateau', 'neon', 'manga', 'gelee'], array_map(static fn (LiveTheme $theme): string => $theme->slug, $catalog->permanent()));
        self::assertSame(['citrouille', 'flocon', 'carnaval', 'poisson', 'ete'], array_map(static fn (LiveTheme $theme): string => $theme->slug, $catalog->seasonal()));
        self::assertSame('pop', $catalog->resolve('a theme taken out since')->slug, 'an unknown slug wears the default');
    }

    public function testEveryThemeHasItsStylesheetScopedUnderItsOwnSlug(): void
    {
        foreach ($this->catalog()->all() as $slug => $theme) {
            $path = self::ROOT.'/assets/styles/quiz_live/themes/'.$slug.'.css';
            self::assertFileExists($path, $slug);
            $css = (string) file_get_contents($path);
            self::assertStringContainsString(\sprintf('.ql[data-live-theme="%s"] {', $slug), $css, $slug.' sets its tokens');
            preg_match_all('/\.ql\[data-live-theme="([^"]+)"\]/', $css, $scopes);
            self::assertSame([$slug], array_values(array_unique($scopes[1])), $slug.' touches no other theme');
            self::assertCount(\count(LiveTheme::WORDS), $theme->words, $slug);
        }

        $sheets = glob(self::ROOT.'/assets/styles/quiz_live/themes/*.css') ?: [];
        self::assertCount(\count($this->catalog()->all()), $sheets, 'no stylesheet without its entry');
    }

    public function testASeasonMayRunOverTheNewYear(): void
    {
        self::assertTrue(LiveTheme::inPeriod('10-31', '10-15', '11-02'));
        self::assertFalse(LiveTheme::inPeriod('11-03', '10-15', '11-02'));
        self::assertTrue(LiveTheme::inPeriod('12-25', '11-25', '01-06'), 'Noël, before the new year');
        self::assertTrue(LiveTheme::inPeriod('01-06', '11-25', '01-06'), 'and after it');
        self::assertFalse(LiveTheme::inPeriod('02-01', '11-25', '01-06'));

        $flocon = $this->catalog()->find('flocon');
        self::assertNotNull($flocon);
        self::assertTrue($flocon->isInSeason(new \DateTimeImmutable('2026-12-24')));
        self::assertSame('25 nov. → 6 janv.', $flocon->periodLabel());
        self::assertFalse($this->catalog()->default()->isInSeason(new \DateTimeImmutable('2026-12-24')), 'a permanent theme has no season');
    }

    public function testAThemeMissingAWordIsRefusedAtTheFirstReading(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'themes');
        file_put_contents($file, "default: a\nthemes:\n    a:\n        name: A\n        description: A\n        swatches: ['#000000', '#111111', '#222222', '#333333', '#444444']\n        wave: sine\n        confetti: rect\n        words: { correct: 'Oui' }\n");

        $this->expectException(\UnexpectedValueException::class);
        try {
            (new LiveThemeCatalog($file))->all();
        } finally {
            unlink($file);
        }
    }
}
