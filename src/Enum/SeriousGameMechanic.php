<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a serious game is played (design/validated/serious-games-neko-con.md §3), read from the
 * `mechanic` key of its manifest.
 */
enum SeriousGameMechanic: string
{
    /** Generated questions in series of 12 on a unit, brightness and comets - the first four games. */
    case Series = 'series';
    /** Hand-written tickets played once, in an order a graph sets - Panique à la Neko-Con. */
    case Scenario = 'scenario';
}
