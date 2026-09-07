<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\String\UnicodeString;

/**
 * The name a downloaded file lands under, built from the name a human gave it.
 *
 * Objects are stored under a random key - `file-library/ca7cfc9b….pdf` - which is the right thing
 * for a bucket and the wrong thing for a browser: with nothing else to go on, that hexadecimal is
 * what the student ends up with in their Téléchargements folder. So the display name is put back on
 * the way out, in the Content-Disposition of the presigned URL
 * (App\Service\FileUploadService::downloadUrl()).
 *
 * **On the way out, not on the way in**, and that is the whole design: a library row can be renamed
 * long after the bytes were written, and one object can be reached through several shares. The name
 * is therefore read at download time and never stored on the object.
 *
 * Which is exactly why it has to be sanitised here: a display name is free text, and it is about to
 * become a header value and then a filename on someone's disk. Two separate jobs, and neither is
 * optional:
 *
 * - sanitize() answers *is this a filename* - no directory in it, nothing a filesystem refuses,
 *   nothing that could close the header's own quoted string or split the header in two;
 * - header() answers *how does a browser read it* - the RFC 6266 pair, an ASCII fallback for the
 *   quoted form and the real UTF-8 name in `filename*`, because a French library is full of accents
 *   and the plain form cannot carry them.
 */
class DownloadFilename
{
    /** What is left when a name sanitises to nothing at all. */
    public const string FALLBACK = 'fichier';

    /**
     * Long enough that no real name is ever shortened, short enough to stay well inside both the
     * 255-byte filename limit of every filesystem involved and the header's own budget.
     */
    private const int MAX_BYTES = 120;

    /** Refused by Windows in a filename, and `"` and `\` also break the quoted form of the header. */
    private const string REFUSED = '<>:"|?*\\/';

    /**
     * The display name, made safe to hand over as a filename.
     *
     * `$storageKey` is optional and does one thing: lend its extension when the name has none. That
     * is not cosmetic - a library row is a *label*, so « Mon cours » is a perfectly ordinary name
     * for a PDF, and a downloaded « Mon cours » with no extension is a file Windows cannot open.
     */
    public static function sanitize(string $name, ?string $storageKey = null): string
    {
        // A name that is not valid UTF-8 cannot be matched on, percent-encoded or cut on a character
        // boundary - so it stops being a special case right here rather than in three places below.
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');

        // Only the last segment: a name carrying separators is either a path or an attempt at one,
        // and in both cases what is wanted is the file at the end of it.
        $name = (string) preg_replace('#^.*[/\\\\]#', '', $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = str_replace(str_split(self::REFUSED), '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        // Leading and trailing dots: a hidden file on Unix, an illegal name on Windows, and "..".
        $name = trim($name, ". \t\n\r\0\x0B");

        if ('' === $name) {
            $name = self::FALLBACK;
        }

        if ('' === self::extensionOf($name) && null !== $storageKey) {
            $borrowed = self::extensionOf($storageKey);
            $name .= '' === $borrowed ? '' : '.'.mb_strtolower($borrowed);
        }

        return self::cap($name);
    }

    /**
     * The Content-Disposition value for an already-sanitised filename.
     *
     * Both forms, in that order, which is what RFC 6266 asks for: everything reads the quoted one,
     * and everything since IE9 prefers `filename*` when it is there. `filename*` is omitted when the
     * name is pure ASCII - not to save bytes, but because the two forms then say the same thing and
     * a header that repeats itself is a header someone will one day read as a disagreement.
     */
    public static function header(string $disposition, string $filename): string
    {
        $ascii = self::asciiFallback($filename);
        $header = \sprintf('%s; filename="%s"', $disposition, $ascii);

        return $ascii === $filename
            ? $header
            : $header.\sprintf("; filename*=UTF-8''%s", rawurlencode($filename));
    }

    /**
     * The trailing extension, or '' when there is none worth calling one.
     *
     * The 1-to-8 alphanumerics is what tells « Cours 3.2 introduction » (no extension, it is a
     * sentence) from « notes.txt » - without it the first would be handed over as a file of type
     * "2 introduction".
     */
    private static function extensionOf(string $name): string
    {
        $dot = strrpos($name, '.');

        if (false === $dot || 0 === $dot) {
            return '';
        }

        $extension = substr($name, $dot + 1);

        return 1 === preg_match('/^[A-Za-z0-9]{1,8}$/', $extension) ? $extension : '';
    }

    /** Shortened to MAX_BYTES, on a character boundary, keeping the extension. */
    private static function cap(string $name): string
    {
        if (\strlen($name) <= self::MAX_BYTES) {
            return $name;
        }

        $extension = self::extensionOf($name);
        $suffix = '' === $extension ? '' : '.'.$extension;
        $base = '' === $suffix ? $name : substr($name, 0, -\strlen($suffix));
        $base = rtrim(mb_strcut($base, 0, self::MAX_BYTES - \strlen($suffix)));

        return ('' === $base ? self::FALLBACK : $base).$suffix;
    }

    /**
     * The same name with nothing but plain ASCII left in it.
     *
     * Transliterated rather than stripped, so « Résumé de séance » degrades to « Resume de seance »
     * instead of « Rsum de sance ». What the transliterator cannot map it writes as `?`, which is
     * itself refused in a filename - hence the second pass, and hence the fallback: a name made only
     * of characters with no Latin form (an emoji, « 《》 ») would otherwise sanitise to nothing.
     */
    private static function asciiFallback(string $filename): string
    {
        // The extension is read off the name rather than off the transliteration, and kept aside
        // while the stem is transliterated: sanitize() has already made it plain ASCII, and pulling
        // it out is what keeps « 🙂.pdf » - whose stem transliterates to nothing at all - from
        // handing over a file called « pdf ».
        $extension = self::extensionOf($filename);
        $suffix = '' === $extension ? '' : '.'.$extension;
        $stem = '' === $suffix ? $filename : substr($filename, 0, -\strlen($suffix));

        $ascii = (new UnicodeString($stem))->ascii()->toString();
        $ascii = str_replace(str_split(self::REFUSED), '', $ascii);
        $ascii = trim((string) preg_replace('/\s+/', ' ', $ascii));
        $ascii = trim($ascii, '. ');

        return ('' === $ascii ? self::FALLBACK : $ascii).$suffix;
    }
}
