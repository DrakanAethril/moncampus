<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Opens an uploaded archive, checks it, and hands back the entries the importer may read.
 *
 * Every safety rule is applied here, on the entry *list*, before a single byte is read out - see
 * App\Service\WikiArchiveSafety for what they are and why. The reader is deliberately the only way
 * into a ZIP in this feature, so there is one place where "is this archive safe" is answered.
 *
 * It recognises two shapes, which the assistant then treats differently:
 *
 *  - **our own**, identified by a `manifest.json` carrying the expected format token: a faithful
 *    restore, `.html` bodies, tree and order rebuilt from the manifest;
 *  - **a generic Markdown tree** (Obsidian, Notion, Docusaurus, a plain folder of `.md`): the
 *    directory structure becomes the node tree.
 */
class WikiArchiveReader
{
    public function __construct(private readonly WikiArchiveSafety $safety)
    {
    }

    /**
     * @return array{zip: \ZipArchive, root: string, manifest: ?array<string, mixed>, entries: list<array{name: string, size: int}>}
     *
     * @throws WikiArchiveException with a translation key when the archive may not be opened
     */
    public function open(string $path): array
    {
        $zip = new \ZipArchive();

        if (true !== $zip->open($path)) {
            throw new WikiArchiveException('wikiImportNotAZipMessage');
        }

        $entries = [];
        $largest = 0;
        $total = 0;

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);

            if (false === $stat) {
                continue;
            }

            /** @var array{name: string, size: int, external_attributes?: int} $stat */
            $name = $stat['name'];

            // Directories are entries too, and carry no bytes worth checking.
            if (str_ends_with($name, '/')) {
                continue;
            }

            if (!$this->safety->isSafePath($name)) {
                $zip->close();

                throw new WikiArchiveException(WikiArchiveSafety::REJECTION_UNSAFE_PATH);
            }

            if ($this->safety->isSymlink((int) ($stat['external_attributes'] ?? 0))) {
                $zip->close();

                throw new WikiArchiveException(WikiArchiveSafety::REJECTION_SYMLINK);
            }

            $size = (int) $stat['size'];
            $largest = max($largest, $size);
            $total += $size;
            $entries[] = ['name' => $name, 'size' => $size];
        }

        $rejection = $this->safety->rejectionFor(\count($entries), $largest, $total);

        if (null !== $rejection) {
            $zip->close();

            throw new WikiArchiveException($rejection);
        }

        if ([] === $entries) {
            $zip->close();

            throw new WikiArchiveException('wikiImportEmptyArchiveMessage');
        }

        $root = $this->rootOf($entries);

        return [
            'zip' => $zip,
            'root' => $root,
            'manifest' => $this->manifestOf($zip, $root),
            'entries' => $entries,
        ];
    }

    /**
     * Reads one entry, refusing anything that turns out bigger than it declared - a ZIP's directory
     * is written by whoever built it and a hostile one lies, so the declared size is a filter and
     * never a guarantee.
     */
    public function read(\ZipArchive $zip, string $name): string
    {
        $contents = $zip->getFromName($name, WikiArchiveSafety::MAX_ENTRY_BYTES + 1);

        if (false === $contents) {
            return '';
        }

        if (\strlen($contents) > WikiArchiveSafety::MAX_ENTRY_BYTES) {
            throw new WikiArchiveException(WikiArchiveSafety::REJECTION_ENTRY_TOO_LARGE);
        }

        return $contents;
    }

    /**
     * Archives usually wrap everything in one folder; treating it as the root is what makes
     * `pages/x.md` mean the same thing whether or not the exporter added a wrapper.
     *
     * @param list<array{name: string, size: int}> $entries
     */
    private function rootOf(array $entries): string
    {
        $first = explode('/', $entries[0]['name'])[0];

        foreach ($entries as $entry) {
            if (!str_starts_with($entry['name'], $first.'/')) {
                return '';
            }
        }

        return $first.'/';
    }

    /** @return ?array<string, mixed> */
    private function manifestOf(\ZipArchive $zip, string $root): ?array
    {
        $raw = $zip->getFromName($root.'manifest.json', 2 * 1024 * 1024);

        if (false === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded) || WikiArchiveExporter::FORMAT !== ($decoded['format'] ?? null)) {
            return null;
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }
}
