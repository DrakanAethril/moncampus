<?php

declare(strict_types=1);

namespace App\Service\Kahoot;

use App\Enum\QuestionType;
use App\Enum\ToleranceMode;
use App\Service\QuizCsvImportException;
use App\Util\NumericRange;

/**
 * Turns the worksheets of a Kahoot game report into a « moncampus-quiz/1 » document - a pure
 * function of the cells, so both report layouts can be tested without a file.
 *
 * Kahoot has exported two layouts over the years, and both are read:
 * - the grid of every answer is « RawReportData Data » in the older one, « Raw Report Data » in the
 *   newer one, which also writes « - » in the answer columns a question does not use;
 * - the question number in that grid is « 1 Quiz » in the older one and a bare « 1 » in the newer.
 * The grid never names a question's type, the tab of that question does (« 10 Puzzle », « 8 Slider »),
 * which is where it is read from; the older layout also calls a question with several right answers
 * a « Quiz », so the count of right answers decides between a QCM and a QCM multiple.
 *
 * Kahoot type     -> Formatool type
 * Quiz            -> qcm, qcm_multi, or vrai_faux when its two propositions are Vrai and Faux
 * Multiple-select -> qcm_multi (or qcm when only one is right)
 * True or false   -> vrai_faux
 * Puzzle          -> ordre (the grid lists the pieces in the right order)
 * Slider          -> numerique, with its range, the margin guessed (KahootSliderTolerance)
 * Type answer     -> reponse_courte, every accepted spelling a variant
 * Poll            -> vote
 * Anything else (word cloud, open-ended, brainstorm…) is left out and named in a note.
 */
final class KahootReportParser
{
    private const array RAW_SHEETS = ['raw report data', 'rawreportdata data'];
    private const string OVERVIEW_SHEET = 'overview';

    // What a proposition column holds when the question has fewer than six (newer layout only).
    private const string UNUSED_ANSWER = '-';

    // The shapes the older layout writes before each proposition on a question's own tab.
    private const array SHAPES = ['▲', '♦', '◆', '●', '■', '⬟', '▼'];

    /**
     * @param array<string, list<list<string>>> $sheets       sheet name => rows, in workbook order
     * @param string                            $fallbackName the file's name, when the report names no game
     *
     * @throws QuizCsvImportException when the file is not a Kahoot report at all
     */
    public static function parse(array $sheets, string $fallbackName): KahootReport
    {
        $raw = null;
        foreach ($sheets as $name => $rows) {
            if (\in_array(mb_strtolower(trim($name)), self::RAW_SHEETS, true)) {
                $raw = $rows;
            }
        }
        if (null === $raw) {
            throw new QuizCsvImportException('kahootImportNotAReportError');
        }

        $header = array_shift($raw) ?? [];
        $columns = [];
        foreach ($header as $index => $name) {
            $columns[mb_strtolower(trim($name))] ??= $index;
        }
        if (!isset($columns['question'])) {
            throw new QuizCsvImportException('kahootImportNotAReportError');
        }

        $answerColumns = [];
        foreach ($columns as $name => $index) {
            // Strictly "answer 1".."answer 6": the grid also carries a bare "answer" (what the player
            // picked) and "answer time" columns, none of which is a proposition.
            if (preg_match('/^answer (\d+)$/', $name, $matches)) {
                $answerColumns[(int) $matches[1]] = $index;
            }
        }
        ksort($answerColumns);

        // One row per player per question: grouped by question, in the order the game played them.
        $types = self::typesBySheet(array_keys($sheets));
        $entries = [];
        $keyColumn = $columns['question number'] ?? $columns['question'];
        foreach ($raw as $row) {
            $key = trim($row[$keyColumn] ?? '');
            if ('' === $key) {
                continue;
            }
            $number = preg_match('/^(\d+)/', $key, $matches) ? (int) $matches[1] : \count($entries) + 1;
            if (!isset($entries[$number])) {
                $options = [];
                foreach ($answerColumns as $index) {
                    $options[] = self::clean($row[$index] ?? '');
                }
                $entries[$number] = [
                    'type' => $types[$number] ?? self::typeFromKey($key),
                    'label' => self::clean($row[$columns['question']] ?? ''),
                    'options' => self::options($options),
                    'correct' => self::clean($row[$columns['correct answers'] ?? -1] ?? ''),
                    'seconds' => (int) round((float) str_replace(',', '.', trim($row[$columns['time allotted to answer (seconds)'] ?? -1] ?? ''))),
                    'rows' => [],
                ];
            }
            $entries[$number]['rows'][] = $row;
        }

        // A question nobody answered - a game stopped early - is absent from the grid, and only its
        // own tab still describes it.
        foreach ($types as $number => $type) {
            if (!isset($entries[$number])) {
                $entries[$number] = self::fromQuestionSheet(self::questionSheet($sheets, $number), $type);
            }
        }
        ksort($entries);

        [$title, $playedOn] = self::overview($sheets);

        $questions = [];
        $notes = [];
        $typeCounts = [];
        foreach ($entries as $questionNumber => $entry) {
            $options = $entry['options'];
            $kahootType = $entry['type'];
            $base = ['label' => $entry['label']];
            if ($entry['seconds'] > 0) {
                $base['seconds'] = $entry['seconds'];
            }

            $question = match ($kahootType) {
                'quiz', 'multiple-select quiz', 'true or false', '' => self::choice($base, $options, $entry['correct'], $questionNumber, $notes),
                'puzzle' => [] === $options ? null : $base + ['type' => QuestionType::Ordre->value, 'answers' => $options, 'correct' => range(1, \count($options))],
                'slider' => self::slider($base, $sheets, $questionNumber, $entry['rows'], $columns, $notes),
                'type answer' => self::shortAnswer($base, $options, $entry['correct']),
                'poll' => \count($options) < 2 ? null : $base + ['type' => QuestionType::Vote->value, 'answers' => $options],
                default => null,
            };

            if (null === $question) {
                if (!\in_array($kahootType, ['quiz', 'multiple-select quiz', 'true or false', '', 'slider'], true)) {
                    $notes[] = new KahootNote(KahootNote::SKIPPED_TYPE, $questionNumber, ['type' => $kahootType]);
                }
                continue;
            }

            $questions[] = $question;
            $type = \is_string($question['type'] ?? null) ? $question['type'] : '';
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        $title ??= trim(pathinfo($fallbackName, \PATHINFO_FILENAME));

        return new KahootReport(
            '' !== $title ? $title : 'Kahoot',
            $playedOn,
            ['format' => 'moncampus-quiz/1', 'template' => ['name' => '' !== $title ? $title : 'Kahoot'], 'questions' => $questions],
            $notes,
            $typeCounts,
        );
    }

    /**
     * Which right answers a choice question names. Kahoot writes them as their texts joined by
     * commas - and a proposition may hold a comma itself (« Non, il est interdit de… »), so the
     * string is matched against every combination of the propositions rather than cut on commas.
     *
     * @param list<string> $options
     *
     * @return list<int>|null 1-based positions, null when nothing matches
     */
    public static function correctPositions(string $correct, array $options): ?array
    {
        $wanted = self::comparable($correct);
        if ('' === $wanted || [] === $options) {
            return null;
        }

        $count = \count($options);
        // Smallest combinations first: a single proposition that happens to contain the whole
        // string wins over two that would spell it together.
        for ($size = 1; $size <= $count; ++$size) {
            foreach (self::combinations($count, $size) as $positions) {
                $labels = array_map(static fn (int $position): string => $options[$position], $positions);
                foreach ([', ', ','] as $separator) {
                    if (self::comparable(implode($separator, $labels)) === $wanted) {
                        return array_map(static fn (int $position): int => $position + 1, $positions);
                    }
                }
            }
        }

        // Last resort, for a report that spaced its list differently: every piece cut on commas has
        // to be one of the propositions, or the answer is not found at all.
        $positions = [];
        foreach (explode(',', $correct) as $piece) {
            $found = null;
            foreach ($options as $position => $option) {
                if (self::comparable($option) === self::comparable($piece)) {
                    $found = $position + 1;
                }
            }
            if (null === $found) {
                return null;
            }
            $positions[] = $found;
        }
        $positions = array_values(array_unique($positions));
        sort($positions);

        return $positions;
    }

    /**
     * @param array<string, mixed> $base
     * @param list<string>         $options
     * @param list<KahootNote>     $notes
     *
     * @return array<string, mixed>|null
     */
    private static function choice(array $base, array $options, string $correct, int $number, array &$notes): ?array
    {
        $positions = self::correctPositions($correct, $options);
        if (null === $positions || \count($options) < 2) {
            $notes[] = new KahootNote(KahootNote::NO_CORRECT_ANSWER, $number);

            return null;
        }

        $comparable = array_map(self::comparable(...), $options);
        $isTrueFalse = 1 === \count($positions) && 2 === \count($options)
            && \in_array($comparable, [['vrai', 'faux'], ['faux', 'vrai'], ['true', 'false'], ['false', 'true']], true);

        $type = match (true) {
            $isTrueFalse => QuestionType::VraiFaux,
            1 === \count($positions) => QuestionType::Qcm,
            default => QuestionType::QcmMulti,
        };

        return $base + ['type' => $type->value, 'answers' => $options, 'correct' => $positions];
    }

    /**
     * @param array<string, mixed> $base
     * @param list<string>         $options
     *
     * @return array<string, mixed>|null
     */
    private static function shortAnswer(array $base, array $options, string $correct): ?array
    {
        $variants = [] !== $options ? $options : array_map(self::clean(...), explode(',', $correct));

        // Kahoot lists « PESTEL, Pestel, pestel »: one spelling is enough once case is ignored.
        $unique = [];
        foreach ($variants as $variant) {
            if ('' !== $variant) {
                $unique[self::comparable($variant)] ??= $variant;
            }
        }

        return [] === $unique ? null : $base + [
            'type' => QuestionType::ReponseCourte->value,
            'answers' => array_values($unique),
            'ignoreCase' => true,
            'tolerateTypo' => false,
        ];
    }

    /**
     * @param array<string, mixed>              $base
     * @param array<string, list<list<string>>> $sheets
     * @param list<list<string>>                $rows    the grid's rows of this question, one per player
     * @param array<string, int>                $columns
     * @param list<KahootNote>                  $notes
     *
     * @return array<string, mixed>|null
     */
    private static function slider(array $base, array $sheets, int $number, array $rows, array $columns, array &$notes): ?array
    {
        $expected = null;
        $range = null;
        foreach (self::questionSheet($sheets, $number) as $row) {
            $label = mb_strtolower(trim($row[0] ?? ''));
            $values = array_values(array_filter(\array_slice($row, 1), static fn (string $cell): bool => '' !== trim($cell)));
            if (\in_array($label, ['correct answer', 'correct answers'], true) && is_numeric($values[0] ?? null)) {
                $expected = (float) $values[0];
            }
            if ('choice range' === $label && preg_match_all('/-?\d+(?:[.,]\d+)?/', implode(' ', $values), $matches) >= 2) {
                $range = NumericRange::normalize(str_replace(',', '.', $matches[0][0]), str_replace(',', '.', $matches[0][1]));
            }
        }

        if (null === $expected) {
            $notes[] = new KahootNote(KahootNote::NO_SLIDER_VALUE, $number);

            return null;
        }

        $answers = [];
        $answerColumn = $columns['answer'] ?? null;
        $scoreColumn = $columns['score (points)'] ?? null;
        foreach ($rows as $row) {
            $value = null !== $answerColumn ? trim($row[$answerColumn] ?? '') : '';
            if (is_numeric($value) && null !== $scoreColumn) {
                $answers[] = ['value' => (float) $value, 'scored' => (float) ($row[$scoreColumn] ?? 0) > 0];
            }
        }

        $margin = KahootSliderTolerance::infer($expected, $answers);
        $notes[] = $margin['guessed']
            ? new KahootNote(KahootNote::TOLERANCE_GUESSED, $number, ['tolerance' => self::number($margin['tolerance'])])
            : new KahootNote(KahootNote::TOLERANCE_UNKNOWN, $number);

        $decimals = floor($expected) === $expected ? 0 : 2;
        $question = $base + [
            'type' => QuestionType::Numerique->value,
            'answer' => $expected,
            'tolerance' => $margin['tolerance'],
            'toleranceMode' => ToleranceMode::Absolute->value,
            'decimals' => $decimals,
        ];
        if (null !== $range) {
            $question['range'] = $range;
        }

        return $question;
    }

    /**
     * A question read from its own tab, for when the grid has no row of it. The two layouts differ
     * here too - the older one interleaves each proposition with its shape (▲ « Ajustement
     * mutuel » ♦ …) - so a row is read by its first cell, and the shapes are dropped.
     *
     * @param list<list<string>> $rows
     *
     * @return array{type: string, label: string, options: list<string>, correct: string, seconds: int, rows: list<list<string>>}
     */
    private static function fromQuestionSheet(array $rows, string $type): array
    {
        $entry = ['type' => $type, 'label' => '', 'options' => [], 'correct' => '', 'seconds' => 0, 'rows' => []];
        foreach ($rows as $row) {
            $first = mb_strtolower(trim($row[0] ?? ''));
            $cells = array_map(self::clean(...), \array_slice($row, 1));
            $texts = array_values(array_filter($cells, static fn (string $cell): bool => '' !== $cell && !\in_array($cell, self::SHAPES, true)));
            if ('' === $entry['label'] && preg_match('/^\d+\s/', $first)) {
                $entry['label'] = $texts[0] ?? '';
            } elseif ('answer options' === $first) {
                $entry['options'] = self::options($texts);
            } elseif ('correct answers' === $first) {
                $entry['correct'] = implode(', ', $texts);
                // A puzzle names its pieces here, in the right order, and nowhere else.
                if ('puzzle' === $type) {
                    $entry['options'] = self::options($texts);
                }
            } elseif ('question duration' === $first && preg_match('/(\d+)/', implode(' ', $texts), $matches)) {
                $entry['seconds'] = (int) $matches[1];
            }
        }

        return $entry;
    }

    /**
     * @param list<string> $cells
     *
     * @return list<string>
     */
    private static function options(array $cells): array
    {
        return array_values(array_filter($cells, static fn (string $cell): bool => '' !== $cell && self::UNUSED_ANSWER !== $cell));
    }

    /**
     * The per-question tabs give each question its Kahoot type: « 10 Puzzle » => [10 => 'puzzle'].
     *
     * @param list<string> $names
     *
     * @return array<int, string>
     */
    private static function typesBySheet(array $names): array
    {
        $types = [];
        foreach ($names as $name) {
            if (preg_match('/^(\d+)\s+(.+)$/u', trim($name), $matches)) {
                $types[(int) $matches[1]] = mb_strtolower(trim($matches[2]));
            }
        }

        return $types;
    }

    /** « 1 Quiz » in the older grid carries the type itself; a bare « 1 » carries none. */
    private static function typeFromKey(string $key): string
    {
        return preg_match('/^\d+\s+(.+)$/u', trim($key), $matches) ? mb_strtolower(trim($matches[1])) : '';
    }

    /**
     * @param array<string, list<list<string>>> $sheets
     *
     * @return list<list<string>>
     */
    private static function questionSheet(array $sheets, int $number): array
    {
        foreach ($sheets as $name => $rows) {
            if (preg_match('/^'.$number.'\s+/', trim($name))) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * The game's name (the first cell of « Overview ») and the day it was played.
     *
     * @param array<string, list<list<string>>> $sheets
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function overview(array $sheets): array
    {
        foreach ($sheets as $name => $rows) {
            if (self::OVERVIEW_SHEET !== mb_strtolower(trim($name))) {
                continue;
            }

            $title = self::clean($rows[0][0] ?? '');
            $playedOn = null;
            foreach ($rows as $row) {
                if ('played on' === mb_strtolower(trim($row[0] ?? '')) && '' !== trim($row[1] ?? '')) {
                    $playedOn = trim($row[1]);
                }
            }

            return ['' !== $title ? $title : null, $playedOn];
        }

        return [null, null];
    }

    /**
     * Every way of picking $size positions among $count, in lexical order.
     *
     * @return \Generator<list<int>>
     */
    private static function combinations(int $count, int $size, int $start = 0): \Generator
    {
        if (0 === $size) {
            yield [];

            return;
        }
        for ($first = $start; $first <= $count - $size; ++$first) {
            foreach (self::combinations($count, $size - 1, $first + 1) as $rest) {
                yield [$first, ...$rest];
            }
        }
    }

    /**
     * The report is HTML-ish: statements come through with entities in them (&#x27;, &nbsp;) and
     * the odd tag. Neither belongs in a question.
     */
    private static function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        // A non-breaking space looks like a space and is not one: it would survive every trim.
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function comparable(string $value): string
    {
        return mb_strtolower(self::clean($value));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', ' '), '0'), ',');
    }
}
