<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BlankMode;
use App\Enum\ZoneSupportKind;
use App\Util\BlankTextParser;
use App\Util\ZoneTextParser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The QuestionType::TexteATrous half of a question, shared verbatim by QuizQuestion (the teacher's
 * editable template row) and QuizInstanceQuestion (its frozen launch-time copy) - screens 2a/2b of
 * design/design_handoff_quiz.
 *
 * Unlike every other question type, a texte à trous keeps no QuizAnswer rows: the whole definition
 * lives in one JSON column. Accepted answers are *per blank and multi-variant* ("32" or
 * "trente-deux"), which a flat answer table with one label per row cannot express without
 * overloading a column to mean a blank number. The JSON is never read raw outside this trait - the
 * accessors below are the only shape contract, so a future key rename stays a one-file change.
 *
 * Stored shape:
 *   {"mode":"banque"|"libre",
 *    "blanks":[{"answers":["32","trente-deux"]}, ...],   // index = blank number, in text order
 *    "distractors":["64","8"],                            // banque mode only, may be absent
 *    "ignoreCase":true, "tolerateTypo":false}
 */
trait QuizQuestionDefinitionTrait
{
    #[ORM\Column(name: 'blanks_config', type: Types::JSON, nullable: true)]
    private ?array $blanksConfig = null;

    /**
     * The QuestionType::Zone / QuestionType::Legende half of a question, same contract as
     * $blanksConfig: one JSON column, never read raw outside this trait. Stored shape
     * ("moncampus-zones/1", étude 2026-08-11):
     *   {"kind":"texte"|"code"|"image",
     *    "language":"html",                          // code only, free text, drives styling only
     *    "content":"…[[z1|<nav>]]…",                 // texte/code - zones marked inline
     *    "markers":{"open":"[[","close":"]]"},       // optional per-question override
     *    "zones":[{"id":"z1","x":0.1,"y":0.2,"w":0.3,"h":0.1}],  // image only, normalized 0..1
     *    "correct":["z4"],                           // Zone questions
     *    "hint":["z1","z4"],                         // zones left visible on "Indice" (entraînement)
     *    "labels":{"z1":"Sélecteur"},                // Legende questions
     *    "distractors":["Attribut"],
     *    "feedback":{"z1":"…","*":"…"}}              // per-wrong-zone correction texts.
     */
    #[ORM\Column(name: 'zone_config', type: Types::JSON, nullable: true)]
    private ?array $zoneConfig = null;

    /**
     * The QuestionType::Apparier half of a question - relate each item of the left column to its
     * item in the right one (mot ↔ définition, date ↔ événement, pays ↔ capitale). Same contract as
     * the two configs above: one JSON column, never read raw outside this trait. Stored shape
     * ("moncampus-apparier/1", 2026-08-11):
     *   {"leftHeader":"Pays", "rightHeader":"Capitale",     // optional column titles
     *    "pairs":[{"id":"p1","left":"France","right":"Paris"}, …],
     *    "distractors":["Bruxelles"],                       // right-hand items matching nothing
     *    "feedback":{"p1":"…","*":"…"}}                     // per-wrongly-matched-pair correction.
     *
     * A pair id is what both the student's answer and the grading key are expressed in, exactly
     * like a zone id one type over - which is why it is stored rather than derived from the row
     * position: reordering the pairs in the editor must not silently rewrite an imported feedback
     * map onto the wrong rows.
     */
    #[ORM\Column(name: 'matching_config', type: Types::JSON, nullable: true)]
    private ?array $matchingConfig = null;

    /**
     * Optional "Correction : …" note shown under this question on the entraînement correction
     * screen (1m) once the student got it wrong. Not blanks-specific - it rides along here because
     * it is the other field both question entities gained at the same time, and keeping the two
     * copies of the mapping in one place is what stops them drifting apart.
     */
    #[ORM\Column(name: 'explanation', type: Types::TEXT, nullable: true)]
    private ?string $explanation = null;

    /**
     * The question's worth, split equally between its blanks at grading time ("1 point réparti
     * équitablement entre les trous (paramétrable)"). Only read for texte à trous - every other
     * type stays the historical all-or-nothing 1 point.
     */
    #[ORM\Column(name: 'points', type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '1.00'])]
    private string $points = '1.00';

    public function getExplanation(): ?string
    {
        return $this->explanation;
    }

    public function setExplanation(?string $explanation): static
    {
        $this->explanation = '' === trim((string) $explanation) ? null : $explanation;

        return $this;
    }

    public function getPoints(): float
    {
        return (float) $this->points;
    }

    public function setPoints(float $points): static
    {
        $this->points = number_format(max(0.0, $points), 2, '.', '');

        return $this;
    }

    /** @return array<string, mixed>|null the raw JSON, for deep-copying to an instance question only */
    public function getBlanksConfig(): ?array
    {
        return $this->blanksConfig;
    }

    public function setBlanksConfig(?array $blanksConfig): static
    {
        $this->blanksConfig = $blanksConfig;

        return $this;
    }

    public function getBlankMode(): BlankMode
    {
        return BlankMode::tryFrom((string) ($this->blanksConfig['mode'] ?? '')) ?? BlankMode::Banque;
    }

    public function setBlankMode(BlankMode $mode): static
    {
        $this->blanksConfig['mode'] = $mode->value;

        return $this;
    }

    /**
     * How many blanks the statement actually contains. Always derived from the text rather than
     * from the stored answers: the teacher can add or remove a "..." at any time, and the text is
     * the single source of truth the editor's "n trous détectés" counter also reads.
     */
    public function getBlankCount(): int
    {
        return BlankTextParser::countBlanks($this->getLabel());
    }

    /** @return list<array{type: 'text'|'blank', value: string, index: int}> */
    public function getBlankSegments(): array
    {
        return BlankTextParser::segments($this->getLabel());
    }

    /**
     * Accepted variants for every blank, padded/truncated to the number of blanks the text has
     * right now - so a stale config saved before the teacher added a "..." never shifts answers
     * onto the wrong blank.
     *
     * @return list<list<string>>
     */
    public function getBlankAnswers(): array
    {
        $stored = $this->blanksConfig['blanks'] ?? [];
        $answers = [];

        for ($i = 0, $count = $this->getBlankCount(); $i < $count; ++$i) {
            $variants = \is_array($stored[$i] ?? null) ? ($stored[$i]['answers'] ?? []) : [];
            $answers[] = self::cleanStrings($variants);
        }

        return $answers;
    }

    /** @param array<array-key, list<string>> $answers re-indexed on the way in: this is a JSON column, and a gap in the keys would store an object */
    public function setBlankAnswers(array $answers): static
    {
        $this->blanksConfig['blanks'] = array_values(array_map(
            static fn (array $variants): array => ['answers' => array_values(array_filter(
                array_map(static fn ($v): string => trim((string) $v), $variants),
                static fn (string $v): bool => '' !== $v,
            ))],
            $answers,
        ));

        return $this;
    }

    /** @return list<string> the optional wrong words mixed into the bank - banque mode only */
    public function getDistractors(): array
    {
        if (BlankMode::Banque !== $this->getBlankMode()) {
            return [];
        }

        $stored = $this->blanksConfig['distractors'] ?? [];

        return self::cleanStrings($stored);
    }

    /**
     * The strings actually usable out of a JSON column: this is stored data, so an entry may be
     * anything at all. Non-scalars and blanks are dropped rather than read as an empty answer,
     * which would grade as a blank nobody can ever get right.
     *
     * @return list<string>
     */
    private static function cleanStrings(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $value) {
            if (\is_scalar($value) && '' !== trim((string) $value)) {
                $clean[] = trim((string) $value);
            }
        }

        return $clean;
    }

    /** @param list<string> $distractors */
    public function setDistractors(array $distractors): static
    {
        $this->blanksConfig['distractors'] = array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $distractors),
            static fn (string $v): bool => '' !== $v,
        ));

        return $this;
    }

    // Screen 2b's two options. Both default the way the créa shows them for a brand new question:
    // accents/case forgiven, typos not.
    public function isIgnoreCase(): bool
    {
        return (bool) ($this->blanksConfig['ignoreCase'] ?? true);
    }

    public function setIgnoreCase(bool $ignoreCase): static
    {
        $this->blanksConfig['ignoreCase'] = $ignoreCase;

        return $this;
    }

    public function isTolerateTypo(): bool
    {
        return (bool) ($this->blanksConfig['tolerateTypo'] ?? false);
    }

    public function setTolerateTypo(bool $tolerateTypo): static
    {
        $this->blanksConfig['tolerateTypo'] = $tolerateTypo;

        return $this;
    }

    /**
     * The words offered to the student in banque mode: one per blank (the first accepted variant,
     * i.e. what the teacher typed on screen 2a) plus the distractors. Returned in definition order -
     * the shuffle is a presentation concern, done per attempt so two students never get the same
     * bank layout (see App\Service\QuizDrawService).
     *
     * @return list<string>
     */
    public function getWordBank(): array
    {
        $words = [];
        foreach ($this->getBlankAnswers() as $variants) {
            if (isset($variants[0])) {
                $words[] = $variants[0];
            }
        }

        return [...$words, ...$this->getDistractors()];
    }

    // ------------------------------------------------------------------
    // Zone / Légende definition - everything below reads $zoneConfig, and
    // is deliberately tolerant of a stale or hand-imported config: unknown
    // ids are dropped, missing keys fall back, nothing throws.
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null the raw JSON, for deep-copying to an instance question only */
    public function getZoneConfig(): ?array
    {
        return $this->zoneConfig;
    }

    public function setZoneConfig(?array $zoneConfig): static
    {
        $this->zoneConfig = $zoneConfig;

        return $this;
    }

    public function getZoneKind(): ZoneSupportKind
    {
        $kind = $this->zoneConfig['kind'] ?? '';

        return ZoneSupportKind::tryFrom(\is_scalar($kind) ? (string) $kind : '') ?? ZoneSupportKind::Texte;
    }

    public function getZoneLanguage(): ?string
    {
        $language = $this->zoneConfig['language'] ?? null;

        return \is_scalar($language) && '' !== trim((string) $language) ? trim((string) $language) : null;
    }

    public function getZoneContent(): string
    {
        $content = $this->zoneConfig['content'] ?? '';

        return \is_scalar($content) ? (string) $content : '';
    }

    /** @return array{open: string, close: string} */
    public function getZoneMarkers(): array
    {
        $markers = $this->zoneConfig['markers'] ?? [];
        $open = \is_array($markers) && \is_scalar($markers['open'] ?? null) ? (string) $markers['open'] : '';
        $close = \is_array($markers) && \is_scalar($markers['close'] ?? null) ? (string) $markers['close'] : '';

        return [
            'open' => '' !== $open ? $open : ZoneTextParser::DEFAULT_OPEN,
            'close' => '' !== $close ? $close : ZoneTextParser::DEFAULT_CLOSE,
        ];
    }

    /** @return list<array{type: 'text'|'zone', value: string, id: string}> empty for the image kind */
    public function getZoneSegments(): array
    {
        $markers = $this->getZoneMarkers();

        return ZoneTextParser::segments($this->getZoneContent(), $markers['open'], $markers['close']);
    }

    /**
     * The code support's numbered rendering - see ZoneTextParser::lines().
     *
     * @return list<list<array{type: 'text'|'zone', value: string, id: string}>>
     */
    public function getZoneLines(): array
    {
        $markers = $this->getZoneMarkers();

        return ZoneTextParser::lines($this->getZoneContent(), $markers['open'], $markers['close']);
    }

    /** @return list<string> every zone the support actually has, whatever the kind */
    public function getZoneIds(): array
    {
        if (ZoneSupportKind::Image === $this->getZoneKind()) {
            return array_map(static fn (array $zone): string => $zone['id'], $this->getImageZones());
        }

        $markers = $this->getZoneMarkers();

        return ZoneTextParser::zoneIds($this->getZoneContent(), $markers['open'], $markers['close']);
    }

    /**
     * Drawn rectangles of an image support, coordinates normalized 0..1 so they survive any
     * display size. Entries missing an id or a coordinate are dropped rather than rendered
     * somewhere random.
     *
     * @return list<array{id: string, x: float, y: float, w: float, h: float}>
     */
    public function getImageZones(): array
    {
        $stored = $this->zoneConfig['zones'] ?? [];
        if (!\is_array($stored)) {
            return [];
        }

        $zones = [];
        foreach ($stored as $zone) {
            if (!\is_array($zone) || !\is_scalar($zone['id'] ?? null) || '' === trim((string) $zone['id'])) {
                continue;
            }
            $coords = [];
            foreach (['x', 'y', 'w', 'h'] as $key) {
                if (!is_numeric($zone[$key] ?? null)) {
                    continue 2;
                }
                $coords[$key] = max(0.0, min(1.0, (float) $zone[$key]));
            }
            $zones[] = ['id' => trim((string) $zone['id']), ...$coords];
        }

        return $zones;
    }

    /** @return list<string> bounded by the support's real zones, so a stale config never grades against ghosts */
    public function getZoneCorrectIds(): array
    {
        return array_values(array_intersect(self::cleanStrings($this->zoneConfig['correct'] ?? []), $this->getZoneIds()));
    }

    /** @return list<string> the zones left visible when the student asks for the hint - entraînement only */
    public function getZoneHintIds(): array
    {
        return array_values(array_intersect(self::cleanStrings($this->zoneConfig['hint'] ?? []), $this->getZoneIds()));
    }

    /** @return array<string, string> zone id => label text, only the entries the teacher actually wrote */
    public function getZoneLabels(): array
    {
        $stored = $this->zoneConfig['labels'] ?? [];
        if (!\is_array($stored)) {
            return [];
        }

        $labels = [];
        foreach ($stored as $zoneId => $text) {
            if (\is_scalar($text) && '' !== trim((string) $text)) {
                $labels[(string) $zoneId] = trim((string) $text);
            }
        }

        return $labels;
    }

    /**
     * One label text per zone of the support, written label first, the zone's own display text as
     * the fallback (a label the teacher never filled in still has to be placeable).
     *
     * @return array<string, string>
     */
    public function getZoneLabelTexts(): array
    {
        $labels = $this->getZoneLabels();
        $markers = $this->getZoneMarkers();
        $texts = ZoneTextParser::zoneTexts($this->getZoneContent(), $markers['open'], $markers['close']);

        $resolved = [];
        foreach ($this->getZoneIds() as $zoneId) {
            $resolved[$zoneId] = $labels[$zoneId] ?? $texts[$zoneId] ?? $zoneId;
        }

        return $resolved;
    }

    /** @return list<string> */
    public function getZoneDistractors(): array
    {
        return self::cleanStrings($this->zoneConfig['distractors'] ?? []);
    }

    /**
     * What a Légende question offers to place: one entry per zone (key = the zone id) plus the
     * distractors under synthetic d0/d1/… keys, in definition order - the shuffle is a
     * presentation concern, done per attempt (App\Service\QuizDrawService::orderZoneChoices()).
     *
     * @return list<array{key: string, text: string}>
     */
    public function getLegendeChoices(): array
    {
        $choices = [];
        foreach ($this->getZoneLabelTexts() as $zoneId => $text) {
            $choices[] = ['key' => $zoneId, 'text' => $text];
        }
        foreach ($this->getZoneDistractors() as $i => $text) {
            $choices[] = ['key' => 'd'.$i, 'text' => $text];
        }

        return $choices;
    }

    /**
     * The correction text for a wrongly clicked zone: its own entry first, the "*" wildcard as
     * the fallback, null when the teacher wrote neither - or when the zone is actually a correct
     * one, which needs no error feedback.
     */
    public function getZoneFeedbackFor(string $zoneId): ?string
    {
        if (\in_array($zoneId, $this->getZoneCorrectIds(), true)) {
            return null;
        }

        $stored = $this->zoneConfig['feedback'] ?? [];
        if (!\is_array($stored)) {
            return null;
        }

        foreach ([$zoneId, '*'] as $key) {
            $text = $stored[$key] ?? null;
            if (\is_scalar($text) && '' !== trim((string) $text)) {
                return trim((string) $text);
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Apparier definition - same tolerance rule as the zones block above:
    // an incomplete pair is dropped rather than rendered as a row nobody
    // can ever get right, unknown ids fall away, nothing throws.
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null the raw JSON, for deep-copying to an instance question only */
    public function getMatchingConfig(): ?array
    {
        return $this->matchingConfig;
    }

    public function setMatchingConfig(?array $matchingConfig): static
    {
        $this->matchingConfig = $matchingConfig;

        return $this;
    }

    /**
     * The two column titles. Empty strings rather than null: they are display-only, and a caller
     * that has to test for null before printing a header is a caller that will forget to.
     *
     * @return array{left: string, right: string}
     */
    public function getMatchingHeaders(): array
    {
        $left = $this->matchingConfig['leftHeader'] ?? null;
        $right = $this->matchingConfig['rightHeader'] ?? null;

        return [
            'left' => \is_scalar($left) ? trim((string) $left) : '',
            'right' => \is_scalar($right) ? trim((string) $right) : '',
        ];
    }

    /**
     * The pairs the question actually has. A pair needs an id and both its sides to exist at all:
     * a half-written row would either be an unanswerable slot or an unreachable choice, and both
     * grade as a wrong answer the student could do nothing about. Duplicate ids keep the first.
     *
     * @return list<array{id: string, left: string, right: string}>
     */
    public function getMatchingPairs(): array
    {
        $stored = $this->matchingConfig['pairs'] ?? [];
        if (!\is_array($stored)) {
            return [];
        }

        $pairs = [];
        $seen = [];
        foreach ($stored as $pair) {
            if (!\is_array($pair)) {
                continue;
            }
            $id = \is_scalar($pair['id'] ?? null) ? trim((string) $pair['id']) : '';
            $left = \is_scalar($pair['left'] ?? null) ? trim((string) $pair['left']) : '';
            $right = \is_scalar($pair['right'] ?? null) ? trim((string) $pair['right']) : '';
            if ('' === $id || '' === $left || '' === $right || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $pairs[] = ['id' => $id, 'left' => $left, 'right' => $right];
        }

        return $pairs;
    }

    /** @return list<string> */
    public function getMatchingPairIds(): array
    {
        return array_map(static fn (array $pair): string => $pair['id'], $this->getMatchingPairs());
    }

    /** @return list<string> the extra right-hand items that belong with no left item at all */
    public function getMatchingDistractors(): array
    {
        return self::cleanStrings($this->matchingConfig['distractors'] ?? []);
    }

    /**
     * What the student picks from: the right side of every pair (keyed by the pair's own id) plus
     * the distractors under synthetic d0/d1/… keys. Returned in definition order - the shuffle is a
     * presentation concern, done per attempt (App\Service\QuizDrawService::orderMatchingChoices()),
     * exactly like a légende's labels. Grading compares the picked choice's *text*, not its key -
     * see App\Service\QuizAnswerChecker::matchingResults().
     *
     * @return list<array{key: string, text: string}>
     */
    public function getMatchingChoices(): array
    {
        $choices = [];
        foreach ($this->getMatchingPairs() as $pair) {
            $choices[] = ['key' => $pair['id'], 'text' => $pair['right']];
        }
        foreach ($this->getMatchingDistractors() as $i => $text) {
            $choices[] = ['key' => 'd'.$i, 'text' => $text];
        }

        return $choices;
    }

    /**
     * The raw feedback map as written - pair id (or the "*" wildcard) => text. This is what the
     * editor re-renders: getMatchingFeedbackFor() resolves the fallback, which would fill every
     * empty row with the wildcard's text and save it back as N per-pair entries.
     *
     * @return array<string, string>
     */
    public function getMatchingFeedbacks(): array
    {
        $stored = $this->matchingConfig['feedback'] ?? [];
        if (!\is_array($stored)) {
            return [];
        }

        $feedbacks = [];
        foreach ($stored as $key => $text) {
            if (\is_scalar($text) && '' !== trim((string) $text)) {
                $feedbacks[(string) $key] = trim((string) $text);
            }
        }

        return $feedbacks;
    }

    /**
     * The correction text for a pair the student got wrong: its own entry first, the "*" wildcard
     * as the fallback, null when the teacher wrote neither.
     */
    public function getMatchingFeedbackFor(string $pairId): ?string
    {
        $feedbacks = $this->getMatchingFeedbacks();

        return $feedbacks[$pairId] ?? $feedbacks['*'] ?? null;
    }
}
