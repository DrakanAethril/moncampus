<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the player reports beside the percentage - the two moments a teacher asks about when a
 * student says « je l'ai regardée ».
 *
 * - **Skip**: a forward jump that lands beyond what had been watched. The stretch between is what was
 *   not seen, and it is kept as a pair of positions so the statistics can say which minutes.
 *   Rewinding, or jumping within what was already watched, is not one: nothing is missed.
 * - **FocusLoss**: the page stopped being the one in front of the student - another tab, another
 *   window, the phone locked - while the video was playing. The player pauses it at that moment, so
 *   the event is also the record of a pause the student did not choose.
 */
enum VideoWatchEventType: string
{
    case Skip = 'skip';
    case FocusLoss = 'focus_loss';
}
