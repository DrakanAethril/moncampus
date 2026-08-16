<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads a page's "Sur cette page" out of its own headings, and stamps the anchors those entries
 * link to onto the same HTML in the same pass.
 *
 * The approach is App\Service\HelpArticleOutline's, deliberately reused rather than an editor
 * plugin: deriving the sommaire instead of storing it is what keeps the two in step, since a writer
 * who renames or reorders a section has nothing else to update.
 *
 * Two differences from the help's version, both because a wiki page is longer than a help article:
 * h3 is collected as well as h2 - a wiki page genuinely has sub-sections - and each entry carries
 * its level so the list can indent. h1 is deliberately ignored: it is the page's own title, which
 * the screen already draws, and repeating it would make every sommaire open on itself.
 */
class WikiPageOutline
{
    // Defaulted rather than required so this stays a plain `new WikiPageOutline()` in a unit test;
    // the container still injects the shared slugger.
    public function __construct(
        private readonly HelpSlug $slug = new HelpSlug(),
    ) {
    }

    /**
     * @return array{html: string, entries: list<array{id: string, title: string, level: int}>}
     */
    public function build(?string $html): array
    {
        if (null === $html || '' === trim($html)) {
            return ['html' => '', 'entries' => []];
        }

        $entries = [];
        $usedIds = [];

        $stamped = preg_replace_callback(
            '#<h(?P<level>[23])(?P<attributes>[^>]*)>(?P<content>.*?)</h(?P=level)>#is',
            /** @param array<array-key, string> $match */
            function (array $match) use (&$entries, &$usedIds): string {
                $level = (int) $match['level'];
                $attributes = $match['attributes'];
                $content = $match['content'];
                $title = trim(html_entity_decode(strip_tags($content), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

                if ('' === $title) {
                    return $match[0];
                }

                $existingId = $this->existingId($attributes);
                $id = $existingId ?? $this->uniqueId($this->slug->from($title), $usedIds);
                $usedIds[] = $id;
                $entries[] = ['id' => $id, 'title' => $title, 'level' => $level];

                return null !== $existingId
                    ? $match[0]
                    : \sprintf('<h%d id="%s"%s>%s</h%d>', $level, $id, $attributes, $content, $level);
            },
            $html,
        );

        return [
            'html' => $stamped ?? $html,
            'entries' => $entries,
        ];
    }

    private function existingId(string $attributes): ?string
    {
        if (1 === preg_match('/\bid\s*=\s*"(?P<id>[^"]+)"/i', $attributes, $match)) {
            return $match['id'];
        }

        return null;
    }

    /** @param list<string> $usedIds */
    private function uniqueId(string $candidate, array $usedIds): string
    {
        // A heading whose title folds to nothing at all still needs an anchor to be linkable.
        if ('' === $candidate) {
            $candidate = 'section';
        }

        if (!\in_array($candidate, $usedIds, true)) {
            return $candidate;
        }

        $suffix = 2;
        while (\in_array($candidate.'-'.$suffix, $usedIds, true)) {
            ++$suffix;
        }

        return $candidate.'-'.$suffix;
    }
}
