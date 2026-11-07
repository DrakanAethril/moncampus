<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

/**
 * One dress of the live contest, as config/quiz_live/themes.yaml describes it - read by
 * LiveThemeCatalog, worn by the room's screen and the phones through its stylesheet
 * (assets/styles/quiz_live/themes/<slug>.css). The words are the theme's own content.
 */
final readonly class LiveTheme
{
    /** The words a theme writes on the screens, each one required. */
    public const array WORDS = ['correct', 'wrong', 'missed', 'sent', 'ready', 'go', 'lobby', 'voted'];

    /**
     * @param list<string>          $swatches the ground first, then the accents
     * @param array<string, string> $words    keyed by WORDS
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public array $swatches,
        public array $words,
        public string $wave,
        public string $confetti,
        public ?string $seasonLabel = null,
        public ?string $seasonFrom = null,
        public ?string $seasonTo = null,
    ) {
    }

    public function isSeasonal(): bool
    {
        return null !== $this->seasonFrom && null !== $this->seasonTo;
    }

    /**
     * Whether the day falls inside the theme's season - a period that runs over the new year
     * (Noël, 25 November to 6 January) wraps.
     */
    public function isInSeason(\DateTimeInterface $day): bool
    {
        if (null === $this->seasonFrom || null === $this->seasonTo) {
            return false;
        }

        return self::inPeriod($day->format('m-d'), $this->seasonFrom, $this->seasonTo);
    }

    /** The rule on primitives: 'MM-DD' strings compare as dates do. */
    public static function inPeriod(string $monthDay, string $from, string $to): bool
    {
        return $from <= $to
            ? $monthDay >= $from && $monthDay <= $to
            : $monthDay >= $from || $monthDay <= $to;
    }

    /** « 15 oct. → 2 nov. », for the pickers. */
    public function periodLabel(): ?string
    {
        if (null === $this->seasonFrom || null === $this->seasonTo) {
            return null;
        }

        return self::dayLabel($this->seasonFrom).' → '.self::dayLabel($this->seasonTo);
    }

    private static function dayLabel(string $monthDay): string
    {
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        [$month, $day] = array_map('intval', explode('-', $monthDay));

        return (1 === $day ? '1er' : (string) $day).' '.($months[$month - 1] ?? '');
    }

    /**
     * What the screens' scripts read: the words, the sound and the confetti.
     *
     * @return array{slug: string, words: array<string, string>, wave: string, confetti: string}
     */
    public function toClient(): array
    {
        return ['slug' => $this->slug, 'words' => $this->words, 'wave' => $this->wave, 'confetti' => $this->confetti];
    }
}
