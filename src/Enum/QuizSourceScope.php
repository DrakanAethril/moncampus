<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a quiz is being generated *from*: a whole séquence, or one of its séances.
 *
 * Two cases and no third, because those are the two things the library holds that carry a course. It
 * is an enum rather than a pair of booleans so that the query string of the import screen
 * (`?sequence=`/`?seance=`) resolves into one value that the prompt, the character counter and - once
 * the two relation tables exist - the pre-checked attachment destination all read the same way.
 */
enum QuizSourceScope: string
{
    case Sequence = 'sequence';

    case Seance = 'seance';
}
