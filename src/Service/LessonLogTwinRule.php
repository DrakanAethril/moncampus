<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LessonSession;

/**
 * The « créneau jumeau »: the same lesson happening RIGHT NOW to the other half of the same class,
 * given by somebody else - so that its cahier de texte can be taken back
 * (design/validated/co-animation.md).
 *
 * **Defined, never configured.** Two teachers standing in the same slot of the same matière of the
 * same class *are* co-animating it: no pairing table, no column, and if the emploi du temps says
 * otherwise next week the definition follows on its own. It deliberately does NOT read the
 * progression's co-animation link either - a cahier de texte is a fact of the timetable, and that
 * link is about who may edit a plan.
 *
 * Note this is **not** the rule the Qualiopi export uses to tell a co-delivery from a redelivery,
 * and the difference is not an inconsistency: the two answer different questions. This one asks
 * "is this the same lesson, happening now, whose text I may take back?", which demands
 * simultaneity. The export asks "who delivered this, to which group?", which does not - if the
 * colleague's group is taught on Thursday because the timetables differ, there is no twin cahier to
 * copy from and the séance is co-animated all the same.
 *
 * Hours are compared as `[start, end)` on the hour columns themselves, never through
 * LessonSession::$length: a créneau is measured in decimal HOURS and a séance in minutes, and this
 * repository has already paid once for mixing the two.
 *
 * @phpstan-type SlotShape array{program: int|null, topic: string|null, day: string|null, start: string|null, end: string|null, teacher: int|null, groupCount: int}
 */
class LessonLogTwinRule
{
    /**
     * @param SlotShape $mine      the créneau whose cahier de texte is being written
     * @param SlotShape $candidate the one offered as a source
     */
    public static function matches(array $mine, array $candidate): bool
    {
        // Every part of the definition is required, so an unknown value can only mean "not a twin".
        // Answering "yes" on a missing hour would offer a colleague's register as this one's own.
        foreach ([$mine['program'], $mine['topic'], $mine['day'], $mine['start'], $mine['end'], $mine['teacher']] as $value) {
            if (null === $value) {
                return false;
            }
        }

        return $candidate['program'] === $mine['program']
            && $candidate['topic'] === $mine['topic']
            && $candidate['day'] === $mine['day']
            // A different teacher, and a KNOWN one: a créneau nobody holds is not a co-animation.
            && null !== $candidate['teacher']
            && $candidate['teacher'] !== $mine['teacher']
            // A co-animated matière is split by construction, so a créneau holding the whole class
            // is not a twin - it is the same lesson given to everybody, which is a different
            // situation and would have no other group to be the twin of.
            && $candidate['groupCount'] > 0
            && null !== $candidate['start']
            && null !== $candidate['end']
            // [start, end) overlap. Touching slots (10-12 then 12-14) are consecutive, not
            // simultaneous, hence the strict comparisons.
            && $candidate['start'] < $mine['end']
            && $candidate['end'] > $mine['start'];
    }

    /** @return SlotShape */
    public static function describe(LessonSession $session): array
    {
        return [
            'program' => $session->getProgram()?->getId(),
            'topic' => $session->getTopic()?->getName(),
            'day' => $session->getDay()?->format('Y-m-d'),
            'start' => $session->getStartHour()?->format('H:i'),
            'end' => $session->getEndHour()?->format('H:i'),
            'teacher' => $session->getTeacher()?->getId(),
            'groupCount' => $session->getOptions()->count(),
        ];
    }

    public static function isTwinOf(LessonSession $mine, LessonSession $candidate): bool
    {
        return self::matches(self::describe($mine), self::describe($candidate));
    }
}
