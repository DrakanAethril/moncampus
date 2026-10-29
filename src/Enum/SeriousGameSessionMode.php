<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a serious-game session is (design/validated/serious-games.md §4): a series on one unit, the
 * final test, or the study of a comet. The three are played the same way, question after question;
 * what differs is what the end of the session decides.
 */
enum SeriousGameSessionMode: string
{
    /** 12 questions on one unit; the score may light the unit and raise its brightness. */
    case Series = 'series';
    /** La Couronne: 12 questions across the chapters, timed, corrected at the end only. */
    case Final = 'final';
    /** 3 questions on one typical error; 2 right out of 3 and the comet is « élucidée ». */
    case Comet = 'comet';
    /**
     * One opening of a ticket of a scenario (design/validated/serious-games-neko-con.md §3.4): its
     * answers are the steps, deliveries and guided questions; its score the stars at closing.
     */
    case Ticket = 'ticket';
}
