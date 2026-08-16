<?php

declare(strict_types=1);

namespace App\Service;

use App\Form\FileUploadDefaults;

/**
 * What kind of file this platform accepts (design/validated/upload-policy.md).
 *
 * **One platform rule. Every upload field may narrow it for its own needs, and may never step
 * outside it.** That is a containment invariant, not a menu of parallel lists, and the distinction
 * is the whole point: parallel per-feature profiles would leave nothing to stop the seventh one
 * from adding an extension the platform never sanctioned; a narrowing cannot, by construction.
 *
 * So a field never *enumerates* what it accepts. It restricts:
 *
 *     UploadPolicy::platform()                                     // the ceiling - the wiki's own rule
 *     UploadPolicy::platform()->restrictTo('pdf')                  // syllabus, alternance calendar
 *     UploadPolicy::platform()->restrictTo('jpg', 'jpeg', 'png', 'webp')
 *
 * restrictTo() throwing on an unknown extension is what turns "don't step outside" from a
 * convention into an error at the call site. What actually holds the rule is
 * App\Tests\Service\UploadPolicyTest::testNarrowingsStayInsideThePlatformList(), which walks every
 * declared narrowing - conventions rot, tests do not.
 *
 * The absolute denylist and the archive-only set are properties of the platform rule, never of a
 * narrowing: a field cannot re-admit `.exe`, and cannot promote `.svg` out of archive-only.
 *
 * Three structural rules matter as much as the list, and each is pinned by its own test:
 *
 * 1. **the extension is cross-checked against the sniffed MIME type**, using the map below, which
 *    deliberately includes the wrong-but-real answers (`text/plain` for a genuine `.csv`,
 *    `application/octet-stream` for a `.pcap`, `application/zip` for every OOXML file). This is not
 *    `Assert\File(extensions:)` on purpose - that is the trap already recorded for this repository,
 *    where a real CSV is guessed as text/plain and rejected;
 * 2. **the denylist applies to every extension segment**, not only the last: `report.pdf.exe` and
 *    `report.exe.pdf` are both refused, because Windows hides known extensions and the last
 *    segment is what decides the served Content-Type;
 * 3. **a file with no extension is refused**, and the name is lowercased and normalised to NFC
 *    first, macOS uploads arriving decomposed.
 *
 * What this object cannot answer is whether a file is *hostile* - an `.exe` inside a `.zip` gets
 * through it untouched. That is App\Service\AntivirusScanner's question, and the two are separate
 * on purpose.
 */
final class UploadPolicy
{
    /**
     * The largest legitimate upload on the platform, driven by video resources
     * (App\Service\VideoUploadValidator::MAX_BYTES). No field may exceed it - which is a different
     * number from App\Form\FileUploadDefaults::MAX_SIZE, the *default* a field gets when it says
     * nothing. Treating the default as if it were the ceiling is precisely how a field would end up
     * "escaping" the rule to serve a legitimate need.
     *
     * frankenphp/conf.d/10-app.ini's upload_max_filesize/post_max_size must sit above THIS, not
     * above the default, or PHP truncates the upload before any constraint runs.
     */
    public const string PLATFORM_MAX_SIZE = '200M';

    public const string VIOLATION_NO_EXTENSION = 'uploadPolicyNoExtensionMessage';
    public const string VIOLATION_FORBIDDEN = 'uploadPolicyForbiddenExtensionMessage';
    public const string VIOLATION_ARCHIVE_ONLY = 'uploadPolicyArchiveOnlyExtensionMessage';
    public const string VIOLATION_UNSUPPORTED = 'uploadPolicyUnsupportedExtensionMessage';
    public const string VIOLATION_MISMATCH = 'uploadPolicyMismatchedTypeMessage';

    /**
     * The platform list: extension => the MIME types it may legitimately sniff as.
     *
     * The second column is what makes this usable rather than theoretical. Every OOXML file is a
     * zip and sniffs as one; a genuine CSV sniffs as text/plain; a .pcap has no signature fileinfo
     * knows and comes back as application/octet-stream. Declaring those keeps real files working
     * while still refusing a shell script named photo.png.
     *
     * @var array<string, list<string>>
     */
    private const array PLATFORM = [
        // Documents
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'rtf' => ['application/rtf', 'text/rtf', 'text/plain'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],

        // Spreadsheets
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        // application/vnd.ms-excel is not a mistake: a CSV saved by Excel sniffs as one, and the
        // quiz CSV import has been accepting it since it shipped.
        'csv' => ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'],

        // Presentations
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],

        // Images
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'avif' => ['image/avif'],
        'heic' => ['image/heic', 'image/heif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],

        // Audio
        'mp3' => ['audio/mpeg'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        'wav' => ['audio/x-wav', 'audio/wav', 'audio/vnd.wave'],
        'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
        'opus' => ['audio/ogg', 'audio/opus'],
        'flac' => ['audio/flac', 'audio/x-flac'],

        // Video. The three answers fileinfo gives an MP4 depending on its ftyp brand are all here,
        // for the same reason App\Service\VideoUploadValidator lists them.
        'mp4' => ['video/mp4', 'application/mp4', 'video/quicktime'],
        'webm' => ['video/webm', 'audio/webm'],
        'mov' => ['video/quicktime', 'video/mp4'],

        // Archives
        'zip' => ['application/zip'],
        '7z' => ['application/x-7z-compressed'],
        'tar' => ['application/x-tar'],
        'gz' => ['application/gzip', 'application/x-gzip'],
        'tgz' => ['application/gzip', 'application/x-gzip', 'application/x-tar'],

        // Data / networking
        'json' => ['application/json', 'text/json', 'text/plain'],
        'xml' => ['text/xml', 'application/xml', 'text/plain'],
        'yaml' => ['text/plain', 'application/x-yaml', 'text/yaml'],
        'yml' => ['text/plain', 'application/x-yaml', 'text/yaml'],
        'sql' => ['text/plain', 'application/sql', 'text/x-sql'],
        'log' => ['text/plain'],
        'ics' => ['text/calendar', 'text/plain'],
        'conf' => ['text/plain'],
        'ini' => ['text/plain'],
        'pcap' => ['application/vnd.tcpdump.pcap', 'application/octet-stream'],
        'pcapng' => ['application/vnd.tcpdump.pcap', 'application/octet-stream'],
        'ipynb' => ['application/json', 'text/plain'],

        // Inert sources. `php` stays allowed on purpose: this is object storage behind a CDN,
        // nothing executes there, and it is the single most common file type of a BTS SIO course.
        'py' => ['text/plain', 'text/x-python', 'text/x-script.python'],
        'java' => ['text/plain', 'text/x-java', 'text/x-java-source'],
        'c' => ['text/plain', 'text/x-c'],
        'cpp' => ['text/plain', 'text/x-c', 'text/x-c++'],
        'h' => ['text/plain', 'text/x-c'],
        'hpp' => ['text/plain', 'text/x-c', 'text/x-c++'],
        'cs' => ['text/plain', 'text/x-csharp'],
        'go' => ['text/plain', 'text/x-go'],
        'rs' => ['text/plain', 'text/x-rust'],
        'php' => ['text/plain', 'text/x-php', 'application/x-php'],
        // .ts is both TypeScript and an MPEG transport stream, and fileinfo answers the second.
        'ts' => ['text/plain', 'text/x-typescript', 'video/mp2t'],
        'css' => ['text/plain', 'text/css'],
    ];

    /**
     * Extensions a double-click executes, plus the Office macro formats. Never accepted, under any
     * narrowing, and checked against **every** segment of the name.
     *
     * @var list<string>
     */
    private const array DENIED = [
        'exe', 'msi', 'com', 'bat', 'cmd', 'scr', 'pif', 'cpl', 'vbs', 'vbe', 'jse', 'wsf', 'wsh',
        'hta', 'ps1', 'psm1', 'reg', 'lnk', 'jar', 'apk', 'ipa', 'app', 'dmg', 'pkg', 'deb', 'rpm',
        'iso', 'img', 'msc', 'sys', 'dll', 'swf', 'chm', 'url', 'docm', 'xlsm', 'pptm', 'dotm',
        'xlam',
    ];

    /**
     * Not dangerous by nature but by how they open - inline on the CDN domain, or through the
     * Windows Script Host. Inside a `.zip` they are inert until somebody deliberately extracts
     * them, which is what lets a web-development course circulate without turning the CDN into a
     * page host.
     *
     * @var list<string>
     */
    private const array ARCHIVE_ONLY = ['html', 'htm', 'xhtml', 'svg', 'mhtml', 'js', 'sh'];

    /**
     * What is still served inline rather than as a download. Everything else gets
     * `Content-Disposition: attachment` in App\Service\FileUploadService - the highest-value measure
     * of this whole policy, and the one that does not depend on the list being right.
     *
     * @var list<string>
     */
    private const array INLINE = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'tif', 'tiff'];

    /** @param array<string, list<string>> $accepted */
    private function __construct(
        private readonly array $accepted,
        private readonly string $maxSize,
    ) {
    }

    /** The platform ceiling - the only place an extension is ever introduced. */
    public static function platform(): self
    {
        return new self(self::PLATFORM, FileUploadDefaults::MAX_SIZE);
    }

    /**
     * Narrows this policy. Naming anything it does not already accept throws, which is what makes
     * "never step outside" an error at the call site rather than a habit.
     */
    public function restrictTo(string ...$extensions): self
    {
        $accepted = [];

        foreach ($extensions as $extension) {
            $extension = mb_strtolower($extension);

            if (!isset($this->accepted[$extension])) {
                throw new \InvalidArgumentException(\sprintf('Extension ".%s" is outside the platform upload policy - see design/validated/upload-policy.md. A field narrows the platform list, it never adds to it.', $extension));
            }

            $accepted[$extension] = $this->accepted[$extension];
        }

        return new self($accepted, $this->maxSize);
    }

    public function withMaxSize(string $maxSize): self
    {
        if (self::toBytes($maxSize) > self::toBytes(self::PLATFORM_MAX_SIZE)) {
            throw new \InvalidArgumentException(\sprintf('Upload size "%s" exceeds the platform ceiling of %s.', $maxSize, self::PLATFORM_MAX_SIZE));
        }

        return new self($this->accepted, $maxSize);
    }

    public function maxSize(): string
    {
        return $this->maxSize;
    }

    /**
     * The same ceiling as a number, for the two callers that cannot use the "20M" shorthand: the
     * staging endpoint, which compares it against a client hint, and the validator's staged-upload
     * branch, which has a size in bytes and no File constraint to delegate to.
     */
    public function maxSizeInBytes(): int
    {
        return self::toBytes($this->maxSize);
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return array_keys($this->accepted);
    }

    /**
     * The union of every MIME type this policy may see - what a help text or an `accept` attribute
     * is built from. Never the decision itself: that is refusalReason()'s, which pairs each type
     * with the extension it belongs to.
     *
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->accepted))));
    }

    public function accepts(string $filename, ?string $sniffedMimeType): bool
    {
        return null === $this->refusalReason($filename, $sniffedMimeType);
    }

    /**
     * Why this file is refused, as a translation key - or null when it passes.
     *
     * @param ?string $sniffedMimeType what fileinfo answered, never what the client claimed. Null
     *                                 means fileinfo had nothing to say: the cross-check exists to
     *                                 catch a lie, and there is then nothing to compare against, so
     *                                 the extension rules alone decide.
     */
    public function refusalReason(string $filename, ?string $sniffedMimeType): ?string
    {
        $segments = self::segmentsOf($filename);

        if ([] === $segments) {
            return self::VIOLATION_NO_EXTENSION;
        }

        // Every segment, not only the last - report.pdf.exe and report.exe.pdf are both refused.
        foreach ($segments as $segment) {
            if (\in_array($segment, self::DENIED, true)) {
                return self::VIOLATION_FORBIDDEN;
            }
        }

        $extension = $segments[\count($segments) - 1];

        if (\in_array($extension, self::ARCHIVE_ONLY, true)) {
            return self::VIOLATION_ARCHIVE_ONLY;
        }

        if (!isset($this->accepted[$extension])) {
            return self::VIOLATION_UNSUPPORTED;
        }

        if (null === $sniffedMimeType || '' === $sniffedMimeType) {
            return null;
        }

        return \in_array(mb_strtolower($sniffedMimeType), $this->accepted[$extension], true)
            ? null
            : self::VIOLATION_MISMATCH;
    }

    /**
     * Is this file still served inline, or handed over as a download?
     *
     * Static rather than per-policy: what a browser does with an object is a property of the object,
     * not of the field that accepted it.
     */
    public static function servesInline(string $filename): bool
    {
        $segments = self::segmentsOf($filename);

        return [] !== $segments && \in_array($segments[\count($segments) - 1], self::INLINE, true);
    }

    // --- The declared narrowings ------------------------------------------------------------
    //
    // Named here rather than inline in each form so that narrowings() can walk them, and so a
    // reader sees the whole map of what this platform accepts where, on one screen.

    /** Messaging, lesson log, library resources, student submissions, signup lists. */
    public static function documents(): self
    {
        return self::platform()->restrictTo(
            'pdf', 'jpg', 'jpeg', 'png', 'webp',
            'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
            'txt', 'zip',
        );
    }

    /** Avatar, quiz question image. */
    public static function images(): self
    {
        return self::platform()->restrictTo('jpg', 'jpeg', 'png', 'webp');
    }

    /** Program syllabus, alternance calendar. */
    public static function pdf(): self
    {
        return self::platform()->restrictTo('pdf');
    }

    /** UFA contract import, quiz import. */
    public static function spreadsheets(): self
    {
        return self::platform()->restrictTo('xlsx', 'csv');
    }

    /** Audio recordings and video resources - the one narrowing that keeps the full ceiling. */
    public static function media(): self
    {
        return self::platform()
            ->restrictTo('mp3', 'm4a', 'wav', 'ogg', 'opus', 'flac', 'mp4', 'webm', 'mov')
            ->withMaxSize(self::PLATFORM_MAX_SIZE);
    }

    /**
     * Every declared narrowing, for the test that asserts each one is a subset of the platform
     * list. The wiki is deliberately absent: it narrows nothing, being the general-purpose
     * workspace, so the platform rule *is* its rule.
     *
     * @return array<string, self>
     */
    public static function narrowings(): array
    {
        return [
            'documents' => self::documents(),
            'images' => self::images(),
            'pdf' => self::pdf(),
            'spreadsheets' => self::spreadsheets(),
            'media' => self::media(),
        ];
    }

    /**
     * The name's extension segments, lowercased, NFC-normalised and in order.
     *
     * The original name is only ever a display label - the S3 key stays generated - so nothing here
     * has to survive round-tripping; it only has to decide.
     *
     * @return list<string>
     */
    private static function segmentsOf(string $filename): array
    {
        $normalised = \Normalizer::normalize($filename, \Normalizer::FORM_C);
        $name = mb_strtolower(false === $normalised ? $filename : $normalised);
        // A leading dot is a hidden file, not an extension: ".gitignore" has none.
        $parts = explode('.', ltrim($name, '.'));
        array_shift($parts);

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /** Parses the "20M"/"200M" shorthand the File constraint uses. */
    private static function toBytes(string $size): int
    {
        if (!preg_match('/^(\d+)([kmg]?)$/i', trim($size), $matches)) {
            throw new \InvalidArgumentException(\sprintf('Unparseable upload size "%s".', $size));
        }

        return (int) $matches[1] * match (mb_strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
    }
}
