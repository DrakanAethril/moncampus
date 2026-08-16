<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Demotes a page's headings by how deep it sits, so the exported PDF has one outline instead of a
 * dozen competing h1s.
 *
 * Every wiki page is written as if it were the only one: its author starts at h1 or h2 without
 * knowing where the page will end up in the tree. Printed one after another, that gives a document
 * a reader cannot navigate and a PDF viewer cannot build a bookmark tree from. A page three levels
 * down therefore opens at h3, and its own h1 is demoted to match.
 *
 * The cap at h6 is not a detail: there is no h7, and a deep branch would otherwise produce markup
 * no renderer understands.
 */
class WikiHeadingShift
{
    private const int MAX_LEVEL = 6;

    /**
     * @param int $depth the node's depth in the tree - 0 for a page hanging off the wiki itself
     */
    public function apply(?string $html, int $depth): string
    {
        if (null === $html || '' === $html) {
            return '';
        }

        if ($depth <= 0) {
            return $html;
        }

        // Opening and closing tags in one pass, so a shifted <h2> cannot end up closed by </h2>.
        return preg_replace_callback(
            '#<(?P<closing>/?)h(?P<level>[1-6])(?P<attributes>[^>]*)>#i',
            function (array $match) use ($depth): string {
                $level = min((int) $match['level'] + $depth, self::MAX_LEVEL);

                return \sprintf('<%sh%d%s>', $match['closing'], $level, $match['attributes']);
            },
            $html,
        ) ?? $html;
    }

    /** The level a page's own title is printed at, given its depth. */
    public function titleLevel(int $depth): int
    {
        return min($depth + 1, self::MAX_LEVEL);
    }
}
