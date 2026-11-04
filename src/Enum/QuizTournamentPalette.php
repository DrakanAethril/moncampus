<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The colours a quiz tournament is dressed in (design/validated/quiz-tournament.md §10.1).
 *
 * Every tournament shares one art direction (H « Pop »: thick black outlines, offset shadows, ink on
 * pastel); what changes is the name, the palette and the optional logo. A palette is **code**, not a
 * colour typed in a form: each one is checked once for contrast against the direction's black ink,
 * which is the only ink the screens use. The tournament's stylesheet sets the three colours as CSS
 * custom properties on `[data-tournament-palette="…"]`; colors() exists for the swatches of the
 * creation form.
 */
enum QuizTournamentPalette: string
{
    case Lavender = 'lavender';
    case Mint = 'mint';
    case Peach = 'peach';

    public function labelKey(): string
    {
        return match ($this) {
            self::Lavender => 'quizTournamentPaletteLavender',
            self::Mint => 'quizTournamentPaletteMint',
            self::Peach => 'quizTournamentPalettePeach',
        };
    }

    /** @return array{background: string, accent: string, accent2: string} */
    public function colors(): array
    {
        return match ($this) {
            self::Lavender => ['background' => '#E9E4FF', 'accent' => '#FFDD3C', 'accent2' => '#FF8FA3'],
            self::Mint => ['background' => '#DDF6EE', 'accent' => '#7CF0C5', 'accent2' => '#8DB8FF'],
            self::Peach => ['background' => '#FFE8DC', 'accent' => '#FFB37A', 'accent2' => '#B9AEFF'],
        };
    }
}
