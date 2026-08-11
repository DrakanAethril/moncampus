<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Reads a Zone/Légende support text, where a clickable zone is written inline as [[id|text]] -
 * the "moncampus-zones/1" marker syntax (étude 2026-08-11). Markers rather than character offsets
 * on purpose: the import format is produced by language models and by hand, and neither can be
 * trusted to count characters. Static like BlankTextParser, and for the same reason: the exact
 * same parse happens in the editor's live preview (client-side twin in
 * assets/controllers/quiz_zone_editor_controller.js), the server-side save, the passation
 * renderer and the grader, and they must never disagree on which zones a support has.
 *
 * A question whose code legitimately contains the default "[[" delimiter can override its markers
 * (e.g. ⟦…⟧) - see ZoneJsonImporter; every method takes the pair.
 */
final class ZoneTextParser
{
    public const string DEFAULT_OPEN = '[[';
    public const string DEFAULT_CLOSE = ']]';

    // Ids stay ASCII-word-ish so they survive form field names, JSON keys and DOM datasets alike.
    private const string ID_PATTERN = '[A-Za-z0-9_-]{1,32}';

    /**
     * The support split into literal text and zones, in reading order. Text items carry an empty
     * id; zone items carry their id and their display text (which may itself contain markup
     * characters - that is the whole point of the code support).
     *
     * @return list<array{type: 'text'|'zone', value: string, id: string}>
     */
    public static function segments(?string $content, string $open = self::DEFAULT_OPEN, string $close = self::DEFAULT_CLOSE): array
    {
        $content = (string) $content;
        if ('' === $content) {
            return [];
        }

        $parts = preg_split(self::pattern($open, $close), $content, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return [];
        }

        $segments = [];
        // preg_split with two capture groups yields [text, id, zoneText, text, id, zoneText, …].
        for ($i = 0, $count = \count($parts); $i < $count; $i += 3) {
            if ('' !== $parts[$i]) {
                $segments[] = ['type' => 'text', 'value' => $parts[$i], 'id' => ''];
            }
            if (isset($parts[$i + 1], $parts[$i + 2])) {
                $segments[] = ['type' => 'zone', 'value' => $parts[$i + 2], 'id' => $parts[$i + 1]];
            }
        }

        return $segments;
    }

    /** @return list<string> zone ids in reading order, first occurrence wins on a duplicate */
    public static function zoneIds(?string $content, string $open = self::DEFAULT_OPEN, string $close = self::DEFAULT_CLOSE): array
    {
        $ids = [];
        foreach (self::segments($content, $open, $close) as $segment) {
            if ('zone' === $segment['type'] && !\in_array($segment['id'], $ids, true)) {
                $ids[] = $segment['id'];
            }
        }

        return $ids;
    }

    /** @return array<string, string> zone id => display text, first occurrence wins on a duplicate */
    public static function zoneTexts(?string $content, string $open = self::DEFAULT_OPEN, string $close = self::DEFAULT_CLOSE): array
    {
        $texts = [];
        foreach (self::segments($content, $open, $close) as $segment) {
            if ('zone' === $segment['type'] && !isset($texts[$segment['id']])) {
                $texts[$segment['id']] = $segment['value'];
            }
        }

        return $texts;
    }

    /**
     * The segments regrouped into lines, for the code support's numbered rendering: text segments
     * split on "\n", zones kept whole on the line they start on. Empty lines stay - collapsing
     * them would renumber everything below.
     *
     * @return list<list<array{type: 'text'|'zone', value: string, id: string}>>
     */
    public static function lines(?string $content, string $open = self::DEFAULT_OPEN, string $close = self::DEFAULT_CLOSE): array
    {
        $lines = [[]];
        foreach (self::segments($content, $open, $close) as $segment) {
            if ('zone' === $segment['type'] || !str_contains($segment['value'], "\n")) {
                $lines[\count($lines) - 1][] = $segment;

                continue;
            }

            foreach (explode("\n", $segment['value']) as $i => $piece) {
                if ($i > 0) {
                    $lines[] = [];
                }
                if ('' !== $piece) {
                    $lines[\count($lines) - 1][] = ['type' => 'text', 'value' => $piece, 'id' => ''];
                }
            }
        }

        return $lines;
    }

    /**
     * What would make this support unusable, for the import's per-question validation: a zone id
     * used twice, or an opening delimiter the pattern never consumed (a mangled zone - or code
     * that needs the per-question markers override).
     *
     * @return list<array{code: 'duplicateId'|'strayMarker', id: string}>
     */
    public static function findIssues(?string $content, string $open = self::DEFAULT_OPEN, string $close = self::DEFAULT_CLOSE): array
    {
        $content = (string) $content;
        $issues = [];

        $seen = [];
        foreach (self::segments($content, $open, $close) as $segment) {
            if ('zone' !== $segment['type']) {
                continue;
            }
            if (isset($seen[$segment['id']]) && !\in_array(['code' => 'duplicateId', 'id' => $segment['id']], $issues, true)) {
                $issues[] = ['code' => 'duplicateId', 'id' => $segment['id']];
            }
            $seen[$segment['id']] = true;
        }

        $stripped = preg_replace(self::pattern($open, $close), '', $content) ?? $content;
        if (str_contains($stripped, $open)) {
            $issues[] = ['code' => 'strayMarker', 'id' => ''];
        }

        return $issues;
    }

    private static function pattern(string $open, string $close): string
    {
        return '/'.preg_quote($open, '/').'('.self::ID_PATTERN.')\|((?:(?!'.preg_quote($close, '/').').)*)'.preg_quote($close, '/').'/su';
    }
}
