<?php

declare(strict_types=1);

namespace App\Service;

use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\Process\Process;

/**
 * The text of a document a teacher keeps in their library - what the Claude connector hands the
 * model when it is asked to start « from my course ».
 *
 * Plain text is read as it is, HTML becomes Markdown, the office formats (docx, odt, pptx, odp,
 * xlsx) are read straight out of their zipped XML, and a PDF goes through `pdftotext` (poppler),
 * run as a separate process: a 200-page PDF parsed in PHP would cost the worker its 256M. A picture
 * is handed over as a picture, since the model reads those; anything else is named rather than
 * guessed at.
 *
 * **Structure, not layout.** Paragraphs, headings, table rows and slides survive; fonts, columns
 * and positions do not. It is enough to write a quiz or a séquence from - which is the whole use.
 */
final class DocumentTextExtractor
{
    /** Text formats read as they are. */
    private const array PLAIN = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'yaml', 'yml', 'xml', 'sql', 'ini', 'conf', 'log',
        'py', 'php', 'js', 'ts', 'java', 'c', 'h', 'cpp', 'cs', 'sh', 'ps1', 'rb', 'go', 'rs', 'css', 'twig', 'dockerfile'];

    private const array IMAGES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /** Above this a picture is described rather than sent: the clients cap an image block near 5 Mo. */
    public const int MAX_IMAGE_BYTES = 3_500_000;

    private const int PDF_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly XlsxSheetReader $sheets,
        private readonly string $pdftotextBinary = 'pdftotext',
    ) {
    }

    public function extract(string $path, string $fileName): ExtractedDocument
    {
        $extension = strtolower(pathinfo($fileName, \PATHINFO_EXTENSION));

        return match (true) {
            \in_array($extension, self::PLAIN, true) => ExtractedDocument::text($this->utf8((string) file_get_contents($path))),
            \in_array($extension, ['html', 'htm'], true) => ExtractedDocument::text($this->markdownOf((string) file_get_contents($path))),
            'pdf' === $extension => $this->pdf($path),
            'docx' === $extension => $this->fromZip($path, 'word/document.xml', $this->wordText(...)),
            'odt' === $extension, 'odp' === $extension => $this->fromZip($path, 'content.xml', $this->openDocumentText(...)),
            'pptx' === $extension => $this->slides($path),
            'xlsx' === $extension => $this->spreadsheet($path),
            isset(self::IMAGES[$extension]) => filesize($path) > self::MAX_IMAGE_BYTES
                ? ExtractedDocument::unreadable('Image trop lourde pour être transmise telle quelle.')
                : ExtractedDocument::image((string) file_get_contents($path), self::IMAGES[$extension]),
            default => ExtractedDocument::unreadable(\sprintf('Le format « .%s » ne se lit pas comme du texte (vidéo, son, archive ou format inconnu).', $extension)),
        };
    }

    private function pdf(string $path): ExtractedDocument
    {
        $process = new Process([$this->pdftotextBinary, '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(self::PDF_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable) {
            return ExtractedDocument::unreadable('La lecture du PDF n\'a pas abouti.');
        }

        if (!$process->isSuccessful()) {
            return ExtractedDocument::unreadable('Ce PDF n\'a pas pu être lu (protégé ou endommagé).');
        }

        // pdftotext separates pages with a form feed; a numbered marker lets the model cite them.
        $pages = explode("\f", rtrim($process->getOutput(), "\f"));
        $text = '';
        foreach ($pages as $index => $page) {
            $text .= \sprintf("\n\n--- Page %d ---\n\n%s", $index + 1, trim($page));
        }

        if ('' === trim((string) preg_replace('/--- Page \d+ ---/', '', $text))) {
            return ExtractedDocument::unreadable('Ce PDF ne contient pas de texte : c\'est probablement un document scanné, fait d\'images de pages.');
        }

        return ExtractedDocument::text(trim($text));
    }

    /**
     * @param \Closure(\DOMDocument): string $reader
     */
    private function fromZip(string $path, string $entry, \Closure $reader): ExtractedDocument
    {
        $xml = $this->zipEntry($path, $entry);

        if (null === $xml) {
            return ExtractedDocument::unreadable('Ce document n\'a pas pu être ouvert (fichier endommagé ou format inattendu).');
        }

        return ExtractedDocument::text(trim($reader($this->dom($xml))));
    }

    /** Word: one line per paragraph, `|` between the cells of a table row. */
    private function wordText(\DOMDocument $document): string
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $lines = [];

        $body = $xpath->query('//w:body/*');
        foreach (false === $body ? [] : $body as $block) {
            if (!$block instanceof \DOMElement) {
                continue;
            }

            if ('tbl' === $block->localName) {
                $rows = $xpath->query('.//w:tr', $block);
                foreach (false === $rows ? [] : $rows as $row) {
                    $cells = [];
                    $cellNodes = $xpath->query('./w:tc', $row);
                    foreach (false === $cellNodes ? [] : $cellNodes as $cell) {
                        $cells[] = trim($this->texts($xpath, './/w:t', $cell));
                    }
                    $lines[] = '| '.implode(' | ', $cells).' |';
                }
                $lines[] = '';
                continue;
            }

            $styles = $xpath->query('./w:pPr/w:pStyle/@w:val', $block);
            $style = false === $styles ? '' : (string) $styles->item(0)?->nodeValue;
            $text = $this->texts($xpath, './/w:t', $block);
            $level = 1 === preg_match('/^(?:Heading|Titre)(\d)$/i', $style, $matches) ? (int) $matches[1] : 0;
            $lines[] = $level > 0 && '' !== trim($text) ? str_repeat('#', min($level, 6)).' '.$text : $text;
        }

        return implode("\n", $lines);
    }

    /** OpenDocument (text and presentation): headings and paragraphs, in order. */
    private function openDocumentText(\DOMDocument $document): string
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $xpath->registerNamespace('draw', 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0');
        $lines = [];

        $pages = $xpath->query('//draw:page');
        if (false !== $pages && $pages->length > 0) {
            foreach ($pages as $index => $page) {
                $lines[] = \sprintf('## Diapositive %d', $index + 1);
                $blocks = $xpath->query('.//text:h|.//text:p', $page);
                foreach (false === $blocks ? [] : $blocks as $block) {
                    $lines[] = trim($block->textContent);
                }
                $lines[] = '';
            }

            return implode("\n", $lines);
        }

        $blocks = $xpath->query('//text:h|//text:p');
        foreach (false === $blocks ? [] : $blocks as $block) {
            $prefix = $block instanceof \DOMElement && 'h' === $block->localName
                ? str_repeat('#', max(1, min(6, (int) ($block->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'outline-level') ?: 1)))).' '
                : '';
            $lines[] = $prefix.trim($block->textContent);
        }

        return implode("\n", $lines);
    }

    /** PowerPoint: the slides in their number order, each one's paragraphs. */
    private function slides(string $path): ExtractedDocument
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return ExtractedDocument::unreadable('Ce document n\'a pas pu être ouvert (fichier endommagé ou format inattendu).');
        }

        $slides = [];
        try {
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = (string) $zip->getNameIndex($index);
                if (1 === preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $matches)) {
                    $slides[(int) $matches[1]] = (string) $zip->getFromIndex($index);
                }
            }
        } finally {
            $zip->close();
        }
        ksort($slides);

        $lines = [];
        foreach ($slides as $number => $xml) {
            $xpath = new \DOMXPath($this->dom($xml));
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $lines[] = \sprintf('## Diapositive %d', $number);
            $paragraphs = $xpath->query('//a:p');
            foreach (false === $paragraphs ? [] : $paragraphs as $paragraph) {
                $text = trim($this->texts($xpath, './/a:t', $paragraph));
                if ('' !== $text) {
                    $lines[] = $text;
                }
            }
            $lines[] = '';
        }

        return [] === $slides
            ? ExtractedDocument::unreadable('Cette présentation ne contient aucune diapositive lisible.')
            : ExtractedDocument::text(trim(implode("\n", $lines)));
    }

    /** A workbook: every sheet, as Markdown-ish rows. */
    private function spreadsheet(string $path): ExtractedDocument
    {
        $reader = $this->sheets;

        try {
            $lines = [];
            foreach ($reader->sheetNames($path) as $sheet) {
                $lines[] = '## '.$sheet;
                foreach ($reader->rows($path, $sheet) as $row) {
                    if ([] !== array_filter($row, static fn (string $cell): bool => '' !== trim($cell))) {
                        $lines[] = '| '.implode(' | ', $row).' |';
                    }
                }
                $lines[] = '';
            }
        } catch (XlsxReadException) {
            return ExtractedDocument::unreadable('Ce classeur n\'a pas pu être lu.');
        }

        return ExtractedDocument::text(trim(implode("\n", $lines)));
    }

    private function texts(\DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);
        $text = '';
        foreach (false === $nodes ? [] : $nodes as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }

    private function zipEntry(string $path, string $entry): ?string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return null;
        }

        try {
            $content = $zip->getFromName($entry);

            return false === $content ? null : $content;
        } finally {
            $zip->close();
        }
    }

    private function dom(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        // LIBXML_NONET: a document is somebody's file, and its XML must never make the server fetch
        // anything. No entity substitution either (that is libxml's default without LIBXML_NOENT).
        $document->loadXML($xml, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);

        return $document;
    }

    private function markdownOf(string $html): string
    {
        return (new HtmlConverter(['strip_tags' => true, 'header_style' => 'atx', 'remove_nodes' => 'script style']))->convert($this->utf8($html));
    }

    private function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}
