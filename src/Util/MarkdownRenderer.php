<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Markdown read into the two shapes the library's fields have.
 *
 * Extracted from App\Command\ImportNotionSequencesCommand (private toText()/toHtml()/toInlineHtml())
 * so the sequence import assistant converts a pasted document exactly the way the Notion import
 * already converted an exported one.
 *
 * Which method to call is decided by the column, not by taste. Of the eleven text fields of a
 * séquence/séance/phase, exactly one is HTML - SeanceTemplate::$cahierDeTexteDescription, rendered
 * `|raw` in library/seance_show.html.twig. All the others are escaped under `white-space: pre-wrap`
 * and edited in a bare TextareaType, so HTML written into them would reach the teacher as tags.
 * Hence: toPlainText() for the ten, toHtml()/toRichHtml() for the one.
 *
 * toRichHtml() exists because toHtml() drops tables, and real séquence sheets are made of them (the
 * Ansible kit's O1→O13 objectives grid, its evaluation table). It is a separate method rather than
 * an option so the Notion command's output does not move: that import has run, its rows exist, and
 * nothing here is worth rewriting them for.
 *
 * This is a deliberately small subset - paragraphs, lists, tables, bold, emphasis, code - not a
 * Markdown implementation. The import prompt asks for exactly that subset, and what a model sends
 * anyway is escaped rather than interpreted.
 */
final class MarkdownRenderer
{
    /**
     * For a plain-text column. Links are flattened to "label (url)", bold markers dropped, and
     * everything else - including a pipe table's rows - kept as its own line: under `pre-wrap` the
     * table still reads as one, and the textarea that edits the field round-trips it.
     */
    public static function toPlainText(string $markdown): string
    {
        $text = self::flattenLinks($markdown);
        $text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text) ?? $text;

        // A table's separator row goes: "|---|---|" is punctuation addressed to a Markdown parser,
        // and there is no parser at the other end of a plain-text field - it would reach the teacher
        // as a line of dashes across the middle of their own table. The rows that carry something
        // stay, which is what keeps the grid readable.
        $lines = array_filter(explode("\n", $text), static fn (string $line): bool => !self::isTableSeparator($line));

        return trim(implode("\n", $lines));
    }

    /**
     * Paragraphs and bullet lists, and nothing else - the shape App\Command\ImportNotionSequencesCommand
     * has always produced for the cahier de texte, kept as it was.
     */
    public static function toHtml(string $markdown): ?string
    {
        return self::render($markdown, rich: false);
    }

    /** toHtml() plus tables, fenced code blocks, bold and inline code. */
    public static function toRichHtml(string $markdown): ?string
    {
        return self::render($markdown, rich: true);
    }

    private static function render(string $markdown, bool $rich): ?string
    {
        $html = '';
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var list<string> $list */
        $list = [];

        $flushParagraph = static function () use (&$html, &$paragraph): void {
            if ([] !== $paragraph) {
                $html .= '<p>'.implode('<br>', $paragraph).'</p>';
                $paragraph = [];
            }
        };
        $flushList = static function () use (&$html, &$list): void {
            if ([] !== $list) {
                $html .= '<ul><li>'.implode('</li><li>', $list).'</li></ul>';
                $list = [];
            }
        };

        $lines = explode("\n", trim($markdown));
        for ($index = 0; $index < \count($lines); ++$index) {
            $line = trim($lines[$index]);

            if ($rich && str_starts_with($line, '```')) {
                $flushParagraph();
                $flushList();
                $html .= self::readFence($lines, $index);
                continue;
            }

            if ($rich && self::opensTable($lines, $index)) {
                $flushParagraph();
                $flushList();
                $html .= self::readTable($lines, $index);
                continue;
            }

            // A separator row is dropped here as well as in toPlainText(), for the same reason, and
            // *without* closing the paragraph: it sits between two rows of the same table, so
            // flushing would split them into two blocks - which is worse than the dashes it removes.
            // In rich mode readTable() has usually consumed it already; this catches the stray one.
            if (self::isTableSeparator($line)) {
                continue;
            }

            if ('' === $line || '---' === $line) {
                $flushParagraph();
                $flushList();
                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/u', $line, $matches)) {
                $flushParagraph();
                $list[] = self::inline($matches[1], $rich);
                continue;
            }

            $flushList();
            $paragraph[] = self::inline($line, $rich);
        }

        $flushParagraph();
        $flushList();

        return '' === $html ? null : $html;
    }

    /**
     * The block between two ``` lines, kept verbatim: a code sample is the one place where "**" and
     * "|" mean themselves. $index is advanced onto the closing fence (or the last line, for a fence
     * nobody closed).
     *
     * @param list<string> $lines
     */
    private static function readFence(array $lines, int &$index): string
    {
        $content = [];
        for (++$index; $index < \count($lines); ++$index) {
            if (str_starts_with(trim($lines[$index]), '```')) {
                break;
            }
            $content[] = rtrim($lines[$index]);
        }

        return '<pre><code>'.htmlspecialchars(implode("\n", $content), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';
    }

    /**
     * A table is a header row *and* the dashed separator under it. Without that second line, pipes
     * are just pipes - a sentence with a "|" in it must not become a one-cell grid.
     *
     * @param list<string> $lines
     */
    private static function opensTable(array $lines, int $index): bool
    {
        if (!str_contains($lines[$index], '|')) {
            return false;
        }

        return self::isTableSeparator($lines[$index + 1] ?? '');
    }

    /**
     * "|---|---|", "| :---: | ---: |", "---|---" - the dashed row under a table's header. It has to
     * carry at least one pipe: a bare "---" is a horizontal rule, which means something else.
     */
    private static function isTableSeparator(string $line): bool
    {
        $line = str_replace(' ', '', trim($line));

        return str_contains($line, '|') && 1 === preg_match('/^\|?:?-+:?(\|:?-+:?)*\|?$/', $line);
    }

    /** @param list<string> $lines */
    private static function readTable(array $lines, int &$index): string
    {
        $header = self::readTableCells($lines[$index], true);
        $index += 2; // the header row and the separator under it

        $body = '';
        for (; $index < \count($lines); ++$index) {
            $line = trim($lines[$index]);
            if ('' === $line || !str_contains($line, '|')) {
                break;
            }
            $body .= '<tr>'.self::readTableCells($line, false).'</tr>';
        }
        --$index; // the loop that called us advances past the last row itself

        return '<table><thead><tr>'.$header.'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    private static function readTableCells(string $line, bool $header): string
    {
        $cells = explode('|', trim(trim($line), '|'));
        $tag = $header ? 'th' : 'td';

        return implode('', array_map(
            static fn (string $cell): string => '<'.$tag.'>'.self::inline(trim($cell), true).'</'.$tag.'>',
            $cells,
        ));
    }

    private static function inline(string $line, bool $rich): string
    {
        return $rich ? self::inlineRich($line) : self::inlinePlain($line);
    }

    private static function inlinePlain(string $line): string
    {
        $line = self::toPlainText($line);
        $line = htmlspecialchars($line, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/\*(.+?)\*/us', '<em>$1</em>', $line) ?? $line;
    }

    /**
     * Backtick spans are split out first and escaped without being interpreted: "**" inside
     * `**srv**` is part of the sample, not emphasis around it.
     */
    private static function inlineRich(string $line): string
    {
        $parts = preg_split('/`([^`]*)`/u', trim(self::flattenLinks($line)), -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return htmlspecialchars($line, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = '';
        foreach ($parts as $position => $part) {
            $escaped = htmlspecialchars($part, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            if (1 === $position % 2) {
                $html .= '<code>'.$escaped.'</code>';
                continue;
            }

            $escaped = preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $escaped) ?? $escaped;
            $html .= preg_replace('/\*(.+?)\*/us', '<em>$1</em>', $escaped) ?? $escaped;
        }

        return $html;
    }

    private static function flattenLinks(string $markdown): string
    {
        return preg_replace_callback(
            '/\[([^\]]*)\]\(([^)]*)\)/u',
            static fn (array $m): string => trim($m[1]) === trim($m[2]) ? $m[1] : \sprintf('%s (%s)', $m[1], $m[2]),
            $markdown,
        ) ?? $markdown;
    }
}
