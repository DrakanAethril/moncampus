<?php

declare(strict_types=1);

namespace App\Service\Survey;

/**
 * Why the individual comparison shows nothing.
 *
 * It refuses, it does not hide (design/validated/surveys.md §7.15): the screen does not tuck a
 * button away, it says why it can show nothing - including to an administrator, because anonymity
 * is not a permission that could be lifted but a property of the stored data.
 */
enum SurveyComparisonRefusal: string
{
    /** One of the two waves is anonymous, so there is no name to put on a row. */
    case AnonymousWave = 'anonymous_wave';

    /** Fewer than two waves in the series - nothing to compare yet. */
    case NotEnoughWaves = 'not_enough_waves';

    /** Nobody is present in the target of both waves. */
    case NoSharedTarget = 'no_shared_target';

    public function messageKey(): string
    {
        return match ($this) {
            self::AnonymousWave => 'surveyIndividualRefusalAnonymousText',
            self::NotEnoughWaves => 'surveyIndividualRefusalWavesText',
            self::NoSharedTarget => 'surveyIndividualRefusalTargetText',
        };
    }
}
