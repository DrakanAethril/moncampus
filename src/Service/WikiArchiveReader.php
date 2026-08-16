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

    /** Read in chunks of this size, so a large entry costs a buffer rather than its whole length. */
    private const int CHUNK_BYTES = 262144;

    /**
     * Reads one entry, stopping if it turns out bigger than it declared - a ZIP's directory is
     * written by whoever built it, and a hostile one lies, so the declared size filters but never
     * guarantees.
     *
     * **Not** `getFromName($name, $maxLength)`: that argument makes ZipArchive *preallocate* a
     * buffer of exactly that size, so passing the 200 MB cap asked PHP for 200 MB per entry and
     * exhausted the memory limit on the first small file. Measured, on an archive of four text
     * files. Streaming keeps the cap meaningful and the memory flat.
     */
    public function read(\ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);

        if (false === $stat) {
            return '';
        }

        if ((int) $stat['size'] > WikiArchiveSafety::MAX_ENTRY_BYTES) {
            throw new WikiArchiveException(WikiArchiveSafety::REJECTION_ENTRY_TOO_LARGE);
        }

        $stream = $zip->getStream($name);

        if (false === $stream) {
            return '';
        }

        $contents = '';

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $contents .= $chunk;

                // The declaration said otherwise; whatever this is, it is not what was announced.
                if (\strlen($contents) > WikiArchiveSafety::MAX_ENTRY_BYTES) {
                    throw new WikiArchiveException(WikiArchiveSafety::REJECTION_ENTRY_TOO_LARGE);
                }
            }
        } finally {
            fclose($stream);
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
        // Through read() like everything else, so the streaming cap applies here too rather than
        // this one place trusting a length argument.
        try {
            $raw = $this->read($zip, $root.'manifest.json');
        } catch (WikiArchiveException) {
            return null;
        }

        if ('' === $raw) {
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
