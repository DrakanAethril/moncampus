<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * QueryValue's twin, one bag over: the same reading for a hand-written form's POST body.
 *
 * InputBag::getInt() throws a BadRequestException - a 400 with no clue as to which field caused it -
 * as soon as the value is present but not convertible, and the empty string counts. On a query
 * string that trap belongs to filter bars (see QueryValue's docblock, and the /assignments incident
 * it was written for); on a POST body it belongs to every `<select>` whose "aucun" option carries
 * `value=""`, which is the shape this app's forms are told to use for an optional choice.
 *
 * It reached production once here too: "+ Poser une évaluation" on a progression posted
 * `sequence=""` for the design's own hors-séquence case, so the only way to pose an evaluation
 * without attaching it to a séquence was a 400 page.
 *
 * The distinction the exception defends is of no use to a form field either: a select left on its
 * empty option and one carrying junk both mean "nothing chosen". Values are read off the raw
 * parameter array rather than through InputBag::get(), which throws on its own account when the
 * field arrives as an array (`name[]=1`).
 *
 * Forms bound to a Symfony Form type read through FormValue instead; this is for the raw request.
 */
final class PostValue
{
    public static function int(Request $request, string $key, int $default = 0): int
    {
        $value = self::raw($request, $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * The reading an optional relation field needs: null says "nothing chosen", where 0 would be a
     * genuine - and never matching - identifier.
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
     * A repeated field (`name="recipients[]"`, `name="placements[7]"`) as the array it is meant to
     * be, keys kept.
     *
     * A form that submits none of them simply omits the key, which InputBag::all() handles - what it
     * throws on is the parameter arriving as a scalar, which is what a tampered or hand-built POST
     * sends, and what a JS client sends when it forgets the brackets. Neither deserves a 400 with no
     * indication of the field: an empty array means "nothing submitted", which every caller here
     * already knows how to handle.
     *
     * @return array<array-key, mixed>
     */
    public static function all(Request $request, string $key): array
    {
        $value = self::raw($request, $key);

        return \is_array($value) ? $value : [];
    }

    /**
     * True only for the forms a checkbox actually posts; everything else, including the empty
     * string and an absent field, is false.
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
        return $request->request->all()[$key] ?? null;
    }
}
