<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a query-string parameter as the type the caller needs, treating anything unusable as
 * "not provided".
 *
 * InputBag::getInt() and getBoolean() do the opposite: since Symfony 6.3 they throw a
 * BadRequestException - a 400 page, with no clue as to which parameter caused it - as soon as the
 * value is present but not convertible. The empty string counts, and the empty string is exactly
 * what a GET filter bar submits when its "Toutes les classes" option carries `value=""`. So the
 * screen died the moment a teacher touched any filter while another one was left blank; that is the
 * production error this class was written for (`/assignments`, `?classe=`).
 *
 * A filter bar has no use for the distinction the exception is defending: an unselected filter and
 * an unparseable one both mean "do not filter on this". A malformed URL should show the unfiltered
 * screen, not a 400.
 *
 * Values are read off the raw parameter array rather than through InputBag::get(), which throws on
 * its own account when the parameter arrives as an array (`?classe[]=1`).
 *
 * Static because there is nothing to inject and nothing to remember - same shape as FormValue and
 * JsonRequestPayload, which solve the same problem one boundary over.
 */
final class QueryValue
{
    public static function int(Request $request, string $key, int $default = 0): int
    {
        $value = self::raw($request, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * The same reading, for a filter that feeds a repository lookup: null says "no filter", where 0
     * would be a genuine - and never matching - identifier.
     */
    public static function nullableInt(Request $request, string $key): ?int
    {
        $value = self::raw($request, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    public static function string(Request $request, string $key, string $default = ''): string
    {
        $value = self::raw($request, $key);

        return \is_scalar($value) ? (string) $value : $default;
    }

    public static function trimmed(Request $request, string $key, string $default = ''): string
    {
        $value = self::raw($request, $key);

        return \is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * True only for the forms a checkbox or a hand-written URL actually uses; everything else,
     * including the empty string, is false.
     */
    public static function bool(Request $request, string $key): bool
    {
        $value = self::raw($request, $key);

        if (!\is_scalar($value)) {
            return false;
        }

        return true === filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
    }

    private static function raw(Request $request, string $key): mixed
    {
        return $request->query->all()[$key] ?? null;
    }
}
