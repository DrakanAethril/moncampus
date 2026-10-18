<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A comet - a typical error the player made twice (design/validated/serious-games.md §1.6).
 * Nothing is ever lost: a comet studied below 2 out of 3 simply « reste en vue ».
 */
enum SeriousGameCometState: string
{
    case InView = 'in_view';
    case Solved = 'solved';
}
