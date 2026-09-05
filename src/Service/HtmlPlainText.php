<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The plain text inside a piece of HugeRTE HTML - for a preview line, a search column, an export
 * cell, anywhere the markup has to go and only the words matter.
 *
 * This exists because Twig's `|striptags` is *not* that: it removes the tags and leaves the
 * entities untouched, so a paragraph stored as "S&eacute;ance" comes out as the seven characters
 * "S&eacute;ance", which the template then escapes into "S&amp;eacute;ance" and the reader sees
 * spelled out on screen. Decoding has to happen before escaping, so it cannot be a Twig filter
 * chain - hence this service and the `plain_text` filter over it (App\Twig\TextExtension).
 *
 * Three choices worth keeping:
 * - Every tag becomes a **space**, not nothing: `<p>Réseaux</p><p>Sécurité</p>` is two words.
 * - `<script>`/`<style>` bodies are dropped whole - a CSS rule is not text anyone wrote to read.
 * - `&nbsp;` is folded to an ordinary space, and a string left with nothing but whitespace answers
 *   **null**: HugeRTE writes `<p>&nbsp;</p>` for a field a teacher emptied, and a screen that
 *   tests the result has to see that for the emptiness it is.
 */
class HtmlPlainText
{
    // A newline that survives the whitespace collapsing - see linesFromHtml().
    private const string KEPT_BREAK = "\x00";

    public function fromHtml(?string $html): ?string
    {
        $text = $this->decode($this->untag($html));
        if (null === $text) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return '' === $text ? null : $text;
    }

    /**
     * The same text, with the document's own paragraphs kept as line breaks - for a body shown at
     * length rather than in one line (a mail with no plain-text part, say, rendered through
     * |nl2br). Only *blocks* break the line: an inline <strong> inside a sentence keeps it one
     * sentence, and the source's own indentation is not a break at all - it says how the HTML was
     * typed, never how it reads.
     */
    public function linesFromHtml(?string $html): ?string
    {
        if (null === $html) {
            return null;
        }

        // Inside <pre>, a newline is content - some mailers send a plain-text body wrapped in one,
        // and flattening it would turn the whole message into a single line. Everywhere else HTML
        // collapses whitespace, so the source's own indentation is not a break the reader should
        // see: it says how the HTML was typed. Parked on a character that cannot appear in text.
        $kept = preg_replace_callback(
            '#<pre\b[^>]*>.*?</pre>#is',
            static fn (array $match): string => str_replace("\n", self::KEPT_BREAK, $match[0]),
            $html,
        ) ?? $html;
        $broken = str_replace(["\r\n", "\r", "\n"], ' ', $kept);

        // Then the document's own line breaks: <br> and the closing tag of each block. A list item
        // and a table row take one line; a paragraph takes a blank line after it.
        $broken = preg_replace('#<br\s*/?>#i', "\n", $broken) ?? $broken;
        $broken = preg_replace('#</(li|tr)\s*>#i', "\n", $broken) ?? $broken;
        $broken = preg_replace('#</(p|div|h[1-6]|blockquote|pre)\s*>#i', "\n\n", $broken) ?? $broken;

        $text = $this->decode($this->untag($broken));
        if (null === $text) {
            return null;
        }

        // Horizontal whitespace only, so the breaks just inserted survive; then a run of blank
        // lines becomes one blank line - an HTML mail is full of empty wrapper blocks, and the
        // reader must not have to scroll past them.
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n[ \n]*\n */u', "\n\n", $text) ?? $text;
        $text = trim(preg_replace('/ *\n */u', "\n", $text) ?? $text);
        $text = str_replace(self::KEPT_BREAK, "\n", $text);

        return '' === $text ? null : $text;
    }

    /** Every tag becomes a space; script and style lose their body with them. */
    private function untag(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        $stripped = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        return preg_replace('/<[^>]*>/', ' ', $stripped) ?? $stripped;
    }

    private function decode(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        return str_replace("\u{a0}", ' ', html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
