<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\QuizTournamentPalette;
use PHPUnit\Framework\TestCase;

/**
 * A tournament's palette is written twice - its colours in App\Enum\QuizTournamentPalette (the
 * swatches of the creation form) and as custom properties in assets/styles/quiz_tournament.css (the
 * tournament's screens). This keeps the two the same palette: a case added to the enum without its
 * block would dress a tournament in nothing.
 */
class QuizTournamentPaletteStylesheetTest extends TestCase
{
    public function testEveryPaletteIsDeclaredInTheStylesheetWithItsOwnColours(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/quiz_tournament.css');

        foreach (QuizTournamentPalette::cases() as $palette) {
            $pattern = '/\.qt\[data-tournament-palette="'.$palette->value.'"\]\s*\{([^}]*)\}/';
            self::assertMatchesRegularExpression($pattern, $css, $palette->value.' has no block in the stylesheet');
            preg_match($pattern, $css, $block);
            $colors = $palette->colors();
            foreach (['background' => '--qt-bg', 'accent' => '--qt-accent', 'accent2' => '--qt-accent2'] as $key => $property) {
                self::assertStringContainsString($property.': '.$colors[$key].';', $block[1], $palette->value.' '.$property);
            }
        }
    }
}
