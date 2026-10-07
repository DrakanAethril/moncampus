<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether a card of a collaborative wall has been let through.
 *
 * `Pending` only ever exists on a wall whose « Validation des cartes avant publication » is on, and
 * only for a card a participant wrote: it is then read by its author and by whoever runs the wall,
 * and by nobody else until it is validated (App\Service\Wall\WallAccess::maySeeCard()).
 */
enum WallCardStatus: string
{
    case Published = 'published';
    case Pending = 'pending';
}
