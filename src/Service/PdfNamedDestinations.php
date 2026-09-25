<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads, out of a PDF Chromium printed, on which page each named destination landed - which is how
 * the Livret Alternant knows the page of a heading without anybody writing it down.
 *
 * Chromium cannot resolve `target-counter()`, so a table of contents cannot be told its own page
 * numbers at print time. What it does do is write one named destination (`/Dests`) per anchor that
 * an internal link points at, carrying the page object the anchor sits on. Walking the page tree in
 * order turns those page objects into page numbers.
 *
 * Deliberately narrow: it reads the uncompressed catalog, page tree and `/Dests` dictionary Skia
 * writes, and nothing else - no object streams, no name trees, no incremental updates, because
 * Gotenberg's Chromium never produces them. That is why it is only ever handed Chromium's own
 * output, never a PDF that went through a merge. Anything it cannot make sense of is an
 * \UnexpectedValueException rather than a guess: a wrong page number in a sommaire is worse than
 * a failed export.
 */
final class PdfNamedDestinations
{
    /**
     * @return array<string, int> anchor id => 1-based page number, in the order the PDF lists them
     */
    public static function read(string $pdf): array
    {
        $catalog = self::catalog($pdf);
        $pageNumbers = array_flip(self::pageObjectIds($pdf, $catalog));

        if (1 === preg_match('~/Dests\s+(\d+)\s+0\s+R~', $catalog, $match)) {
            $dests = self::object($pdf, (int) $match[1]);
        } elseif (1 === preg_match('~/Dests\s*(<<.*?>>)~s', $catalog, $match)) {
            $dests = $match[1];
        } else {
            return [];
        }

        preg_match_all('~/([^\s/\[\]<>()]+)\s*\[\s*(\d+)\s+0\s+R~', $dests, $matches, \PREG_SET_ORDER);

        $pages = [];
        foreach ($matches as [, $name, $pageObjectId]) {
            $page = $pageNumbers[(int) $pageObjectId] ?? throw new \UnexpectedValueException(\sprintf('Destination "%s" points at object %s, which is not a page.', $name, $pageObjectId));
            $pages[self::decodeName($name)] = $page + 1;
        }

        return $pages;
    }

    public static function pageCount(string $pdf): int
    {
        return \count(self::pageObjectIds($pdf, self::catalog($pdf)));
    }

    private static function catalog(string $pdf): string
    {
        if (1 !== preg_match('~\d+\s+0\s+obj\s*(<<\s*/Type\s*/Catalog\b.*?)endobj~s', $pdf, $match)) {
            throw new \UnexpectedValueException('No document catalog found in the PDF.');
        }

        return $match[1];
    }

    /**
     * The leaves of the page tree, in document order.
     *
     * @return list<int>
     */
    private static function pageObjectIds(string $pdf, string $catalog): array
    {
        if (1 !== preg_match('~/Pages\s+(\d+)\s+0\s+R~', $catalog, $match)) {
            throw new \UnexpectedValueException('The PDF catalog has no page tree.');
        }

        $pages = [];
        $walk = static function (int $id, int $depth) use (&$walk, &$pages, $pdf): void {
            if ($depth > 32) {
                throw new \UnexpectedValueException('The PDF page tree is deeper than any real document.');
            }

            $node = self::object($pdf, $id);
            if (1 !== preg_match('~/Kids\s*\[([^\]]*)\]~', $node, $kids)) {
                $pages[] = $id;

                return;
            }

            preg_match_all('~(\d+)\s+0\s+R~', $kids[1], $refs);
            foreach ($refs[1] as $kid) {
                $walk((int) $kid, $depth + 1);
            }
        };
        $walk((int) $match[1], 0);

        return $pages;
    }

    /** The dictionary of one object - only ever asked of objects that carry no stream. */
    private static function object(string $pdf, int $id): string
    {
        if (1 !== preg_match('~(?:^|[\r\n])'.$id.'\s+0\s+obj\s*(<<.*?)endobj~s', $pdf, $match)) {
            throw new \UnexpectedValueException(\sprintf('Object %d not found in the PDF.', $id));
        }

        return $match[1];
    }

    /** PDF names escape delimiters and anything outside printable ASCII as #XX. */
    private static function decodeName(string $name): string
    {
        return (string) preg_replace_callback('~#([0-9A-Fa-f]{2})~', static fn (array $m): string => \chr((int) hexdec($m[1])), $name);
    }
}
