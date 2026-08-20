<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a delivery of a séance IS, relative to the first one - the distinction the Qualiopi export
 * has to print in words (design/validated/co-animation.md).
 *
 * The document could already say « dédoublée », but only in the sequential sense: it printed
 * « redispensée le 12/03 à 10 h au groupe 2 … sur un créneau distinct ». Both halves of that
 * sentence are FALSE for a co-animated séance - nothing is re-given later, and the créneau is not
 * distinct: the two deliveries are simultaneous, in two rooms, by two teachers. Printing it puts a
 * false statement in an audit file, which is worse than printing nothing.
 *
 * **The split key is the teacher, not the hour.** That is deliberately not the twin rule the cahier
 * de texte uses (App\Service\LessonLogTwinRule), which requires overlapping hours, and the
 * difference is not an inconsistency: the two answer different questions. The cahier de texte asks
 * "is this the same lesson happening right now, so that its text can be taken back?", which demands
 * simultaneity. The export asks "who delivered this, to which group?", which does not - if the
 * colleague's group is taught on Thursday because the timetables differ, there is no twin cahier to
 * copy from and the séance is co-animated all the same.
 */
enum ProgressionDeliveryKind: string
{
    /** The reference delivery - and any whole-class one, which nobody is left out of. */
    case Primary = 'primary';

    /** The same group again, on another créneau: nothing is re-given, the séance simply spans two slots. */
    case Continuation = 'continuation';

    /** Another group, the SAME teacher: given a second time, on a distinct créneau. */
    case Redelivery = 'redelivery';

    /** Another group, ANOTHER teacher: given simultaneously, in another room. */
    case CoDelivery = 'co_delivery';

    /**
     * Another group, but the timetable does not say by whom - so neither sentence can be printed.
     *
     * A separate case rather than a default onto Redelivery, because both wordings make a positive
     * claim: "redispensée sur un créneau distinct" and "co-animée simultanément" are each a
     * statement about who stood in front of the group. When the créneau names nobody, the document
     * says the group also received the séance and stops there.
     */
    case Unknown = 'unknown';

    /**
     * Label each delivery of one séance, in chronological order.
     *
     * @param list<array{group: string, teacher: int|null}> $deliveries group is '' for a whole-class delivery
     *
     * @return list<self> parallel to $deliveries
     */
    public static function classify(array $deliveries): array
    {
        // The reference is the first delivery scoped to a group - the same one the learner-hours
        // rule measures against. A séance with no group delivery at all has nothing to compare.
        $referenceGroup = null;
        $referenceTeacher = null;
        foreach ($deliveries as $delivery) {
            if ('' !== $delivery['group']) {
                $referenceGroup = $delivery['group'];
                $referenceTeacher = $delivery['teacher'];
                break;
            }
        }

        $kinds = [];
        foreach ($deliveries as $delivery) {
            $kinds[] = match (true) {
                '' === $delivery['group'], $delivery['group'] === $referenceGroup => self::Primary,
                default => self::compare($referenceTeacher, $delivery['teacher']),
            };
        }

        // A group delivered twice is a continuation of itself, not a redelivery: its apprenants sat
        // through both. Only the SECOND and later occurrence of a group is one, so the reference
        // group's repeats are relabelled here rather than in the match above, which only knows
        // about the first.
        $seen = [];
        foreach ($deliveries as $index => $delivery) {
            if ('' === $delivery['group']) {
                continue;
            }

            if (isset($seen[$delivery['group']])) {
                $kinds[$index] = self::Continuation;
            }

            $seen[$delivery['group']] = true;
        }

        return $kinds;
    }

    private static function compare(?int $referenceTeacher, ?int $teacher): self
    {
        if (null === $referenceTeacher || null === $teacher) {
            return self::Unknown;
        }

        return $referenceTeacher === $teacher ? self::Redelivery : self::CoDelivery;
    }
}
