<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns a page's HTML body into the plain text App\Entity\WikiNode::$bodyText carries.
 *
 * That column exists so the search can use a FULLTEXT index at all, and so that looking for
 * "table" finds the word rather than every page holding a table. It is rebuilt on every save
 * rather than derived at query time - the read side is where a wiki is used.
 *
 * &nbsp; is folded to an ordinary space on purpose: HugeRTE emits it constantly (French
 * typography puts one before every ':' and inside guillemets), and a search for "test" must match
 * a page that wrote "«&nbsp;test&nbsp;»".
 */
class WikiBodyText
{
    public function fromHtml(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        // Script and style carry text that is not content - indexing a CSS rule would only make
        // the search answer nonsense.
        $stripped = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // Every tag becomes a space rather than nothing: two adjacent blocks are two words.
        $stripped = preg_replace('/<[^>]*>/', ' ', $stripped) ?? $stripped;

        $text = html_entity_decode($stripped, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return '' === $text ? null : $text;
    }
}
