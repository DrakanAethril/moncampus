<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whose créneaux a séquence may be laid onto, by matière.
 *
 * A Progression is anchored on ONE Topic (see that entity's docblock), so restricting the
 * placement to a single matière has always been the behaviour AND the default - this enum exists
 * to open it, not to close it. A teacher holding two matières with the same class can spend one
 * séquence across both (self::All), or lay it exclusively on the other one (self::Specific,
 * pointing at ProgressionSequence::$slotTopic).
 *
 * The candidates are never "every matière of the class": they are the progression teacher's own
 * Topics inside the same Program - see App\Service\ProgressionSlotPool::candidateTopics(). Reaching
 * a colleague's créneaux is not a thing this module lets anyone do.
 */
enum ProgressionSlotTopicScope: string
{
    case Own = 'own';
    case All = 'all';
    case Specific = 'specific';
}
