<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LessonSession;
use App\Entity\Progression;
use App\Entity\ProgressionSequence;
use App\Entity\Topic;
use App\Enum\ProgressionSlotTopicScope;
use App\Repository\LessonSessionRepository;
use App\Repository\TopicRepository;

/**
 * The single answer to "which créneaux may this séquence be laid onto".
 *
 * Both sides of the module ask it: the automatic walk (ProgressionPlacementService) and the manual
 * 2b picker with its associate/fit endpoints. They used to ask it separately - both spelling out
 * findOrderedForTopic($progression->getTopic()) - which was harmless while the answer was a
 * constant. It stops being one as soon as a séquence can name its own matière and composition, and
 * two screens disagreeing about which créneaux are eligible is exactly the bug where the picker
 * offers a créneau the walk then refuses to use.
 *
 * Note what is NOT in here: "une séance par semaine". That one is not a property of a créneau at
 * all - it depends on what the walk has already placed - so it lives in the placement service where
 * the ledger is. A pool is a set of créneaux; a rhythm is a walk.
 */
class ProgressionSlotPool
{
    public function __construct(
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly TopicRepository $topicRepository,
    ) {
    }

    /**
     * Every créneau any séquence of this progression may use, in one chronological order.
     *
     * Deliberately the UNION rather than a per-séquence list: replan() chains the séquences by
     * carrying a cursor from one to the next, and a cursor is an index into one ordered list. Each
     * séquence then skips what its own filter rejects (see accepts()), which keeps the chaining
     * meaningful even when two séquences of the same progression read different matières.
     *
     * @return list<LessonSession>
     */
    public function forProgression(Progression $progression): array
    {
        $topics = [];

        $own = $progression->getTopic();
        if (null !== $own) {
            $topics[(int) $own->getId()] = $own;
        }

        foreach ($progression->getSequences() as $sequence) {
            foreach ($this->topicsFor($sequence) as $topic) {
                $topics[(int) $topic->getId()] = $topic;
            }
        }

        return $this->lessonSessionRepository->findOrderedForTopics(array_values($topics));
    }

    /**
     * The créneaux this ONE séquence may use, filter applied - what the 2b picker lists and what
     * its submit re-validates against.
     *
     * @return list<LessonSession>
     */
    public function forSequence(ProgressionSequence $sequence): array
    {
        $slots = $this->lessonSessionRepository->findOrderedForTopics($this->topicsFor($sequence));

        return array_values(array_filter(
            $slots,
            static fn (LessonSession $slot): bool => $sequence->getSlotComposition()->accepts($slot),
        ));
    }

    /**
     * Is this créneau one of the séquence's, both by matière and by composition? Asked per créneau
     * during the walk, against the progression-wide list.
     */
    public function accepts(ProgressionSequence $sequence, LessonSession $slot): bool
    {
        if (!$sequence->getSlotComposition()->accepts($slot)) {
            return false;
        }

        $slotTopicId = $slot->getTopic()?->getId();
        if (null === $slotTopicId) {
            return false;
        }

        foreach ($this->topicsFor($sequence) as $topic) {
            if ($topic->getId() === $slotTopicId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The matières whose créneaux this séquence may use.
     *
     * Always re-derived from the scope rather than read straight off $slotTopic, so that a matière
     * reassigned to a colleague - or made inactive - since the choice was made stops being reached:
     * the séquence falls back to the progression's own matière rather than keeping a créneau it is
     * no longer entitled to. The stored choice is a preference, candidateTopics() is the authority.
     *
     * @return list<Topic>
     */
    public function topicsFor(ProgressionSequence $sequence): array
    {
        $progression = $sequence->getProgression();
        if (null === $progression) {
            return [];
        }

        $own = $progression->getTopic();
        $ownList = null === $own ? [] : [$own];

        return match ($sequence->getSlotTopicScope()) {
            ProgressionSlotTopicScope::Own => $ownList,
            ProgressionSlotTopicScope::All => $this->candidateTopics($progression),
            ProgressionSlotTopicScope::Specific => $this->pickSpecific($progression, $sequence->getSlotTopic()) ?? $ownList,
        };
    }

    /**
     * The matières offered by the "Créneaux utilisés" select: the progression teacher's own Topics
     * inside the same class, its own matière included and listed first.
     *
     * The progression's teacher, not whoever is looking - a staff member opening someone else's
     * progression through ProgressionVoter's bypass must see that teacher's matières, same
     * reasoning as ProgressionController::unusedSequenceInstances().
     *
     * @return list<Topic>
     */
    public function candidateTopics(Progression $progression): array
    {
        $program = $progression->getProgram();
        $teacher = $progression->getTeacher();
        $own = $progression->getTopic();

        if (null === $program || null === $teacher) {
            return null === $own ? [] : [$own];
        }

        $topics = null === $own ? [] : [$own];

        foreach ($this->topicRepository->findForTeacherInProgram($program, $teacher) as $topic) {
            if ($topic !== $own) {
                $topics[] = $topic;
            }
        }

        return $topics;
    }

    /** @return list<Topic>|null null when the stored matière is no longer a candidate */
    private function pickSpecific(Progression $progression, ?Topic $wanted): ?array
    {
        if (null === $wanted) {
            return null;
        }

        foreach ($this->candidateTopics($progression) as $topic) {
            if ($topic === $wanted) {
                return [$topic];
            }
        }

        return null;
    }
}
