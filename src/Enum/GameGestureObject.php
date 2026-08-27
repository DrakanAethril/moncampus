<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a malus may be about - and the list is closed at two (§4, decision 6).
 *
 * There is exactly one malus in the whole system and it bears on dress or on behaviour. The
 * constraint lives in the form, not only in the documentation: the screen offers these two objects
 * and nothing else, so a teacher cannot turn the gesture into a second disciplinary register by
 * typing a third subject into a free field.
 *
 * The design recommended dropping the malus entirely, so as not to sanction twice a fact the
 * établissement's own rules already handle. It was kept - and this is the shape that keeps it from
 * becoming a register.
 */
enum GameGestureObject: string
{
    case Dress = 'dress';
    case Behaviour = 'behaviour';

    public function labelKey(): string
    {
        return match ($this) {
            self::Dress => 'gameGestureObjectDressLabel',
            self::Behaviour => 'gameGestureObjectBehaviourLabel',
        };
    }
}
