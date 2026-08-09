<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads an article's "Sur cette page" out of its own level-2 headings, and stamps the anchors those
 * entries link to onto the same HTML in the same pass (design_handoff_aide, écran 2).
 *
 * Deriving the sommaire instead of storing it is what keeps the two in step: an admin who renames
 * or reorders a section in HugeRTE cannot leave a stale entry behind, because there is nothing else
 * to update. Only h2 is collected - h3 exists in the editor's toolbar and is a subdivision inside a
 * step, not a step.
 */
class HelpArticleOutline
{
    // Defaulted rather than required so the outline stays a plain `new HelpArticleOutline()` in a
    // unit test; the container still injects the shared service.
    public function __construct(
        private readonly HelpSlug $slug = new HelpSlug(),
    ) {
    }

    /**
     * @return array{html: string, entries: list<array{id: string, title: string}>}
     */
    public function build(?string $html): array
    {
        if (null === $html || '' === trim($html)) {
            return ['html' => '', 'entries' => []];
        }

        $entries = [];
        $usedIds = [];

        $stamped = preg_replace_callback(
            '#<h2(?P<attributes>[^>]*)>(?P<content>.*?)</h2>#is',
            /** @param array<array-key, string> $match */
            function (array $match) use (&$entries, &$usedIds): string {
                $attributes = $match['attributes'];
                $content = $match['content'];
                $title = trim(html_entity_decode(strip_tags($content), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

                if ('' === $title) {
                    return $match[0];
                }

                $existingId = $this->existingId($attributes);
                $id = $existingId ?? $this->uniqueId($this->slug->from($title), $usedIds);
                $usedIds[] = $id;
                $entries[] = ['id' => $id, 'title' => $title];

                return null !== $existingId
                    ? $match[0]
                    : sprintf('<h2 id="%s"%s>%s</h2>', $id, $attributes, $content);
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
        if (!in_array($candidate, $usedIds, true)) {
            return $candidate;
        }

        $suffix = 2;
        while (in_array($candidate.'-'.$suffix, $usedIds, true)) {
            ++$suffix;
        }

        return $candidate.'-'.$suffix;
    }
}
