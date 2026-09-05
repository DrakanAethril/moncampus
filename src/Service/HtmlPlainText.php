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
    public function fromHtml(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        $stripped = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $stripped = preg_replace('/<[^>]*>/', ' ', $stripped) ?? $stripped;

        $text = html_entity_decode($stripped, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return '' === $text ? null : $text;
    }
}
