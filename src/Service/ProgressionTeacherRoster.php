<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\Progression;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\LessonSessionRepository;

/**
 * Who delivers this progression's matière, and to which group - the « Formateurs » of the export's
 * cover and the « Co-animation » block of screen 2a, answered once.
 *
 * Every row is MEASURED off the timetable: a teacher's group and room are read from the créneaux
 * they actually hold on the progression's Topic, never stored. That is the design's own rule (see
 * design/validated/co-animation.md, "why the link lives on the Progression and not on the Topic"):
 * who teaches a matière is already derivable, and the only thing the join table adds is who may
 * edit the plan. So the link decides membership, and the timetable decides what is printed next to
 * each name - if a créneau moves to another room next week, the document follows on its own.
 *
 * The owner is listed first and always, whether or not they hold a créneau: they own the plan even
 * on a matière whose créneaux staff has since reassigned, and a cover with no formateur at all
 * would be worse than one naming a teacher without a room.
 *
 * @phpstan-type RosterRow array{teacher: User, isOwner: bool, groups: list<string>, rooms: list<string>, slotCount: int}
 */
class ProgressionTeacherRoster
{
    public function __construct(private readonly LessonSessionRepository $lessonSessionRepository)
    {
    }

    /** @return list<RosterRow> */
    public function forProgression(Progression $progression): array
    {
        return $this->forProgressions([$progression])[(int) $progression->getId()] ?? [];
    }

    /**
     * The same answer for a whole list of progressions in ONE créneau query - what screen 3a needs,
     * since it prints the co-animation chip on every row and a per-row lookup would be the N+1 this
     * repository has already paid for once elsewhere.
     *
     * @param list<Progression> $progressions
     *
     * @return array<int, list<RosterRow>> keyed by progression id
     */
    public function forProgressions(array $progressions): array
    {
        $topics = [];
        foreach ($progressions as $progression) {
            $topic = $progression->getTopic();
            if (null !== $topic) {
                $topics[(int) $topic->getId()] = $topic;
            }
        }

        $slotsByTopic = $this->slotsByTopic(array_values($topics));

        $rosters = [];
        foreach ($progressions as $progression) {
            $topicId = (int) $progression->getTopic()?->getId();
            $rosters[(int) $progression->getId()] = $this->roster($progression, $slotsByTopic[$topicId] ?? []);
        }

        return $rosters;
    }

    /**
     * The teachers this progression may still name as co-animators: whoever holds a créneau of its
     * matière for its class, minus the ones already named and minus the owner.
     *
     * The restriction is the design's, and it is not cosmetic - "la liste ne peut donc nommer
     * personne qui ne soit pas réellement là". Filtered in PHP rather than in SQL because the set
     * is a year of one matière's créneaux, which is small, and because the same read already had
     * to happen for the rows above.
     *
     * @return list<User>
     */
    public function candidates(Progression $progression, ?string $search = null): array
    {
        $topic = $progression->getTopic();
        if (null === $topic) {
            return [];
        }

        $needle = null === $search ? '' : mb_strtolower(trim($search));

        $candidates = [];
        foreach ($this->lessonSessionRepository->findOrderedForTopic($topic) as $slot) {
            $teacher = $slot->getTeacher();
            if (null === $teacher || $teacher === $progression->getTeacher() || $progression->isCoTeacher($teacher)) {
                continue;
            }

            $label = $this->label($teacher);
            if ('' !== $needle && !str_contains(mb_strtolower($label), $needle)) {
                continue;
            }

            $candidates[(int) $teacher->getId()] = $teacher;
        }

        $rows = array_values($candidates);
        usort($rows, fn (User $a, User $b): int => $this->label($a) <=> $this->label($b));

        return $rows;
    }

    /**
     * @param list<LessonSession> $slots the whole matière's créneaux, any teacher
     *
     * @return list<RosterRow>
     */
    private function roster(Progression $progression, array $slots): array
    {
        $rows = [];
        foreach ($progression->getTeachers() as $teacher) {
            $rows[(int) $teacher->getId()] = [
                'teacher' => $teacher,
                'isOwner' => $teacher === $progression->getTeacher(),
                'groups' => [],
                'rooms' => [],
                'slotCount' => 0,
            ];
        }

        foreach ($slots as $slot) {
            $teacherId = (int) $slot->getTeacher()?->getId();
            // Not `$rows[$teacherId]?->…`: the nullsafe operator does not guard the array access,
            // and a matière's créneaux legitimately name teachers who are on neither side of this
            // progression's link.
            if (!isset($rows[$teacherId])) {
                continue;
            }

            ++$rows[$teacherId]['slotCount'];

            foreach ($slot->getOptions() as $option) {
                $rows[$teacherId]['groups'][] = $option->getShortName();
            }

            $room = $slot->getClassRoom()?->getName();
            if (null !== $room) {
                $rows[$teacherId]['rooms'][] = $room;
            }
        }

        return array_values(array_map(
            static function (array $row): array {
                $row['groups'] = array_values(array_unique($row['groups']));
                $row['rooms'] = array_values(array_unique($row['rooms']));
                sort($row['groups']);
                sort($row['rooms']);

                return $row;
            },
            $rows,
        ));
    }

    /**
     * @param list<Topic> $topics
     *
     * @return array<int, list<LessonSession>> keyed by topic id
     */
    private function slotsByTopic(array $topics): array
    {
        $byTopic = [];
        foreach ($this->lessonSessionRepository->findOrderedForTopics($topics) as $slot) {
            $byTopic[(int) $slot->getTopic()?->getId()][] = $slot;
        }

        return $byTopic;
    }

    private function label(User $teacher): string
    {
        return $teacher->getDisplayName() ?? $teacher->getUsername();
    }
}
