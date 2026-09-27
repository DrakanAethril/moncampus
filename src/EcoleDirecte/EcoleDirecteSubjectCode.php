<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * How École Directe writes a subject code into a route.
 *
 * Its website swaps the first `/` for fifteen underscores and the first `+` for fifteen `P`s before
 * putting a code in a path - a code such as `EPS/SP` would otherwise read as two segments. The
 * gradebook routes also join the sub-subject with `¤` (`INFO¤` when there is none), which the cahier
 * de texte routes do not. The result is then percent-encoded, `¤` being outside ASCII.
 */
final class EcoleDirecteSubjectCode
{
    private const array SUBSTITUTIONS = ['/' => '_______________', '+' => 'PPPPPPPPPPPPPPP'];

    private const string SUB_SUBJECT_SEPARATOR = '¤';

    /** The segment of a cahier de texte route: `cahierdetexte/seance/{class}/{this}/{date}`. */
    public static function lessonLogSegment(string $code): string
    {
        return rawurlencode(self::substitute($code));
    }

    /** The segment of a gradebook route: `…/matieres/{this}/notes`. */
    public static function gradebookSegment(string $code, string $subCode = ''): string
    {
        $joined = str_contains($code, self::SUB_SUBJECT_SEPARATOR) ? $code : $code.self::SUB_SUBJECT_SEPARATOR.$subCode;

        return rawurlencode(self::substitute($joined));
    }

    /** Only the first occurrence of each, as École Directe's own String.replace() does. */
    private static function substitute(string $code): string
    {
        foreach (self::SUBSTITUTIONS as $search => $replacement) {
            $position = strpos($code, $search);
            if (false !== $position) {
                $code = substr_replace($code, $replacement, $position, \strlen($search));
            }
        }

        return $code;
    }
}
