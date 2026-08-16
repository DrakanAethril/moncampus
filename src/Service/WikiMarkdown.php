<?php

declare(strict_types=1);

namespace App\Service;

use League\CommonMark\CommonMarkConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * The two conversions the Markdown archive needs, and the reason there are two of them.
 *
 * A page's body is stored as HTML, and `HTML → Markdown → HTML` loses colours, alignment, colspan,
 * the callouts and the Mermaid SVG. So the archive writes **both**: the `.md` as the portable,
 * human-readable artefact, and the `.html` beside it, which the manifest declares authoritative on
 * re-import. That gives a lossless round-trip for our own archives while still producing something
 * another tool can read. Storing pages as Markdown instead was rejected: it would cap the editor at
 * whatever Markdown expresses, which contradicts the whole point of the feature.
 *
 * App\Util\MarkdownRenderer stays what it is - a deliberately small subset for the library's
 * fields - and is not stretched to cover this.
 */
class WikiMarkdown
{
    private ?HtmlConverter $toMarkdown = null;
    private ?CommonMarkConverter $toHtml = null;

    /** For the archive's `.md` files. */
    public function fromHtml(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        $this->toMarkdown ??= new HtmlConverter([
            // Anything the converter has no Markdown for is kept as inline HTML rather than
            // flattened to text: the .md is the portable copy, not a lossy one.
            'strip_tags' => false,
            'hard_break' => true,
            'header_style' => 'atx',
        ]);

        return $this->toMarkdown->convert($html);
    }

    /**
     * For a **generic** Markdown tree (Obsidian, Notion, Docusaurus, a plain folder of .md) - never
     * for our own archives, which carry their `.html` and use it.
     *
     * The result still goes through the page sanitizer at the call site: this is somebody else's
     * file, and CommonMark's `allow_unsafe_links: false` only covers part of what that means.
     */
    public function toHtml(string $markdown): string
    {
        $this->toHtml ??= new CommonMarkConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        return (string) $this->toHtml->convert($markdown);
    }

    /**
     * The title of a generic Markdown file: its first level-1 heading, or - failing that - its
     * file name, which is what a folder of notes usually means by a title anyway.
     */
    public function titleOf(string $markdown, string $filename): string
    {
        if (1 === preg_match('/^\s*#\s+(?P<title>.+?)\s*$/m', $markdown, $match)) {
            return trim($match['title']);
        }

        $base = pathinfo($filename, \PATHINFO_FILENAME);
        // "01-adressage-ip" reads as a file name, not as a title.
        $base = preg_replace('/^\d+[-_. ]+/', '', $base) ?? $base;

        return '' === trim($base) ? $filename : ucfirst(str_replace(['-', '_'], ' ', trim($base)));
    }

    /**
     * The YAML front-matter each `.md` carries, so a file read on its own - outside the archive -
     * still knows what it is.
     *
     * @param array<string, string|int> $fields
     */
    public function frontMatter(array $fields): string
    {
        $lines = ['---'];

        foreach ($fields as $key => $value) {
            // Quoted and escaped: a title holding a colon would otherwise produce YAML that says
            // something else entirely.
            $lines[] = \is_int($value)
                ? \sprintf('%s: %d', $key, $value)
                : \sprintf('%s: "%s"', $key, str_replace(['\\', '"'], ['\\\\', '\\"'], $value));
        }

        $lines[] = '---';

        return implode("\n", $lines)."\n\n";
    }

    /** Strips the front-matter back off on import, returning the body alone. */
    public function withoutFrontMatter(string $markdown): string
    {
        return preg_replace('/\A---\R.*?\R---\R+/s', '', $markdown) ?? $markdown;
    }
}
