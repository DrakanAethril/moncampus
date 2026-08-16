<?php

declare(strict_types=1);

namespace App\Service;

/**
 * KaTeX's stylesheet, made self-contained so a formula prints.
 *
 * Gotenberg has **no browser origin**: the HTML it converts cannot resolve a relative URL, so
 * `url(fonts/KaTeX_Main-Regular.woff2)` fetches nothing and every formula falls back to the body
 * face - which for KaTeX means glyphs of the wrong metrics in the wrong places, not merely a
 * different font. The stylesheet is therefore inlined with its fonts turned into data: URIs.
 *
 * Only the **woff2** sources are kept. The vendored CSS declares woff2, woff and ttf for each face;
 * Chromium takes the first it supports, so carrying the other two would triple the payload - about
 * 300 kB of woff2 against nearly a megabyte - for bytes that are never read.
 *
 * Built once per process and remembered: an export of a hundred-page wiki asks for it once, but the
 * worker serves many exports.
 */
class WikiPdfStylesheet
{
    private ?string $katex = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function katex(): string
    {
        if (null !== $this->katex) {
            return $this->katex;
        }

        $directory = $this->projectDir.'/public/katex';
        $css = @file_get_contents($directory.'/katex.min.css');

        if (false === $css) {
            // A missing stylesheet must not take the export down with it: the formulas print in the
            // body face, everything else is unaffected.
            return $this->katex = '';
        }

        // Drop the woff and ttf sources, keeping each @font-face's woff2 one.
        $css = preg_replace('#,\s*url\(fonts/[^)]+\.(?:woff|ttf)\)\s*format\("(?:woff|truetype)"\)#i', '', $css) ?? $css;

        $css = preg_replace_callback(
            '#url\(fonts/(?P<file>[^)]+\.woff2)\)#i',
            static function (array $match) use ($directory): string {
                $bytes = @file_get_contents($directory.'/fonts/'.$match['file']);

                return false === $bytes
                    ? $match[0]
                    : \sprintf('url(data:font/woff2;base64,%s)', base64_encode($bytes));
            },
            $css,
        ) ?? $css;

        return $this->katex = $css;
    }
}
