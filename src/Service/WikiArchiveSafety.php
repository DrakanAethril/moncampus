<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whether an uploaded archive may be opened at all.
 *
 * Nothing else in this codebase has had to read a ZIP a user supplied, so none of this existed
 * before (design/validated/wiki.md, "Import assistant"). An archive is the one upload that is not a
 * file but a *program for creating files*, and each rule here stops it from writing somewhere - or
 * as much as - it should not:
 *
 *  - **zip-slip**: an entry named `../../etc/passwd` extracts outside the destination. The name is
 *    normalised and refused if it resolves upwards, is absolute, or carries a Windows drive or
 *    backslash - a ZIP written on Windows may hold either.
 *  - **zip bomb**: a few kilobytes that decompress to gigabytes. Three caps rather than one,
 *    because they fail differently: too many entries, one entry too large, or a total too large.
 *  - **symlinks**: an entry that is a link rather than a file can point at anything on the host.
 *    ZipArchive does not extract them as links, but it does report their mode, so they are refused
 *    by name rather than trusted to be inert.
 *
 * Everything is checked against the entry *list*, before a single byte is written: a hostile
 * archive must never reach the filesystem, not merely be cleaned up afterwards. The uncompressed
 * sizes come from the ZIP's own directory, which a hostile archive can lie about - which is why the
 * extraction also stops when the running total passes MAX_TOTAL_BYTES rather than trusting the
 * declaration alone.
 */
class WikiArchiveSafety
{
    /**
     * A wiki of five thousand pages does not exist; an archive claiming that many entries is a
     * bomb or a mistake, and either way not something to spend a request on.
     */
    public const int MAX_ENTRIES = 5000;

    /** The platform's own upload ceiling - no single file inside may beat what one could upload. */
    public const int MAX_ENTRY_BYTES = 200 * 1024 * 1024;

    /** Generous for a real wiki with its attachments, and nowhere near what a bomb expands to. */
    public const int MAX_TOTAL_BYTES = 500 * 1024 * 1024;

    public const string REJECTION_TOO_MANY_ENTRIES = 'wikiImportTooManyEntriesMessage';
    public const string REJECTION_ENTRY_TOO_LARGE = 'wikiImportEntryTooLargeMessage';
    public const string REJECTION_ARCHIVE_TOO_LARGE = 'wikiImportArchiveTooLargeMessage';
    public const string REJECTION_UNSAFE_PATH = 'wikiImportUnsafePathMessage';
    public const string REJECTION_SYMLINK = 'wikiImportSymlinkMessage';

    /**
     * Does this entry name stay inside the extraction root?
     *
     * Normalised by hand rather than with realpath(): the file does not exist yet, so there is
     * nothing on disk to resolve against - the question is about the *name*.
     */
    public function isSafePath(string $entry): bool
    {
        // A backslash or a drive letter means the archive was written on Windows; both would slip
        // through a check that only knows about '/'.
        if (str_contains($entry, '\\') || 1 === preg_match('/^[a-zA-Z]:/', $entry)) {
            return false;
        }

        if ('' === $entry || str_starts_with($entry, '/')) {
            return false;
        }

        $depth = 0;

        foreach (explode('/', $entry) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                --$depth;

                if ($depth < 0) {
                    return false;
                }

                continue;
            }

            ++$depth;
        }

        // "pages/.." resolves to the root itself, which is not a file - and is how a crafted name
        // ends up overwriting a directory.
        return $depth > 0;
    }

    /**
     * The archive-wide caps, as a translation key or null when everything is within them.
     *
     * @param int $entries        how many entries the archive declares
     * @param int $largestEntry   the biggest single uncompressed size
     * @param int $totalUncompressed the sum of them
     */
    public function rejectionFor(int $entries, int $largestEntry, int $totalUncompressed): ?string
    {
        if ($entries > self::MAX_ENTRIES) {
            return self::REJECTION_TOO_MANY_ENTRIES;
        }

        if ($largestEntry > self::MAX_ENTRY_BYTES) {
            return self::REJECTION_ENTRY_TOO_LARGE;
        }

        if ($totalUncompressed > self::MAX_TOTAL_BYTES) {
            return self::REJECTION_ARCHIVE_TOO_LARGE;
        }

        return null;
    }

    /**
     * ZipArchive reports an entry's external attributes in its high 16 bits, Unix-style: 0xA000 is
     * S_IFLNK. A link is refused rather than trusted to extract inertly.
     */
    public function isSymlink(int $externalAttributes): bool
    {
        return 0xA000 === (($externalAttributes >> 16) & 0xF000);
    }
}
