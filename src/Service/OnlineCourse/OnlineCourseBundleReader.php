<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Service\WikiArchiveSafety;

/**
 * Reads the archive of an interactive course and decides whether it may be published - without
 * writing anything (design/validated/cours-en-ligne.md, §6).
 *
 * An interactive course is HTML, CSS and JavaScript, which the platform accepts nowhere on their
 * own (App\Service\UploadPolicy): it travels as a `.zip`, and this is what opens it. The rules:
 *
 * - an `index.html` at the root - or inside one single wrapping folder, which is what « Compresser »
 *   produces on a Mac, and which is then removed from every path;
 * - the operating system's own litter (`__MACOSX/`, `.DS_Store`, `Thumbs.db`) is ignored: an
 *   archive refused because of a file its author never created would be inexplicable;
 * - **everything else must be a kind of file a web page is made of** (EXTENSIONS). One file that is
 *   not refuses the **whole archive**, naming it - a course published with a silent hole in it
 *   would fail in front of a class instead of in front of its author;
 * - no path that leaves the folder, no link, no absurd count or size
 *   (App\Service\WikiArchiveSafety, the rules already written for the wiki's archives).
 *
 * The sizes come from the archive's own directory, which a hostile one can lie about: the publisher
 * counts again as it writes.
 */
class OnlineCourseBundleReader
{
    public const int MAX_FILES = 2000;

    /** What the files may weigh once unpacked - the answer to an archive of zeros. */
    public const int MAX_TOTAL_BYTES = 500 * 1024 * 1024;

    public const int MAX_PATH_LENGTH = 200;

    public const string ENTRY_POINT = 'index.html';

    /**
     * What an archive may hold, with the type each file is served as. A closed list: a file is
     * served by the CDN under the type written here, never under one sniffed from its bytes.
     *
     * @var array<string, string>
     */
    public const array EXTENSIONS = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'xml' => 'application/xml',
        'vtt' => 'text/vtt; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'wasm' => 'application/wasm',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly WikiArchiveSafety $safety,
    ) {
    }

    public static function contentTypeOf(string $path): ?string
    {
        return self::EXTENSIONS[mb_strtolower(pathinfo($path, \PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * @throws OnlineCourseMaterialRefused naming the first thing that makes the archive unacceptable
     */
    public function read(string $archivePath): OnlineCourseBundle
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archivePath, \ZipArchive::RDONLY)) {
            throw new OnlineCourseMaterialRefused('onlineCourseBundleNotAnArchiveMessage');
        }

        try {
            return $this->readEntries($zip);
        } finally {
            $zip->close();
        }
    }

    private function readEntries(\ZipArchive $zip): OnlineCourseBundle
    {
        $entries = [];
        $total = 0;

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if (false === $stat) {
                continue;
            }

            /** @var array{name: string, size: int, external_attributes?: int} $stat */
            $name = $stat['name'];

            // A folder entry carries no file, and the litter is nobody's file.
            if (str_ends_with($name, '/') || $this->isLitter($name)) {
                continue;
            }

            if (!$this->safety->isSafePath($name) || 1 === preg_match('/[\x00-\x1f]/', $name)) {
                throw new OnlineCourseMaterialRefused('onlineCourseBundleUnsafePathMessage', ['%file%' => $name]);
            }

            $attributes = 0;
            $zip->getExternalAttributesIndex($index, $opsys, $attributes);
            if ($this->safety->isSymlink((int) $attributes)) {
                throw new OnlineCourseMaterialRefused('onlineCourseBundleUnsafePathMessage', ['%file%' => $name]);
            }

            if (null === self::contentTypeOf($name)) {
                throw new OnlineCourseMaterialRefused('onlineCourseBundleForbiddenFileMessage', ['%file%' => $name]);
            }

            if (\count($entries) >= self::MAX_FILES) {
                throw new OnlineCourseMaterialRefused('onlineCourseBundleTooManyFilesMessage', ['%max%' => (string) self::MAX_FILES]);
            }

            $total += (int) $stat['size'];
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new OnlineCourseMaterialRefused('onlineCourseBundleTooLargeMessage', ['%max%' => '500 Mo']);
            }

            $entries[$name] = (int) $stat['size'];
        }

        if ([] === $entries) {
            throw new OnlineCourseMaterialRefused('onlineCourseBundleNoIndexMessage');
        }

        $wrapper = $this->wrappingFolder(array_keys($entries));
        $files = [];
        foreach ($entries as $name => $size) {
            $path = self::normalize(substr($name, \strlen($wrapper)));
            if (mb_strlen($path) > self::MAX_PATH_LENGTH) {
                throw new OnlineCourseMaterialRefused('onlineCourseBundleUnsafePathMessage', ['%file%' => $name]);
            }
            $files[$path] = ['path' => $path, 'entry' => $name, 'size' => $size];
        }

        if (!isset($files[self::ENTRY_POINT])) {
            throw new OnlineCourseMaterialRefused('onlineCourseBundleNoIndexMessage');
        }

        ksort($files);

        return new OnlineCourseBundle(array_values($files), $total);
    }

    /**
     * The single folder everything sits in, with its trailing slash - or the empty string when the
     * archive has an `index.html` at its root, or no such folder.
     *
     * @param list<string> $names
     */
    private function wrappingFolder(array $names): string
    {
        if (\in_array(self::ENTRY_POINT, $names, true)) {
            return '';
        }

        $folder = null;
        foreach ($names as $name) {
            $slash = strpos($name, '/');
            if (false === $slash) {
                return '';
            }

            $first = substr($name, 0, $slash + 1);
            if (null !== $folder && $first !== $folder) {
                return '';
            }
            $folder = $first;
        }

        return $folder ?? '';
    }

    /** `./a//b` and `a/b` are one file; the checked name has no `..` left to resolve. */
    private static function normalize(string $path): string
    {
        return implode('/', array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment && '.' !== $segment)));
    }

    private function isLitter(string $name): bool
    {
        $base = basename($name);

        return str_starts_with($name, '__MACOSX/')
            || str_contains($name, '/__MACOSX/')
            || \in_array($base, ['.DS_Store', 'Thumbs.db', 'desktop.ini'], true)
            || str_starts_with($base, '._');
    }
}
