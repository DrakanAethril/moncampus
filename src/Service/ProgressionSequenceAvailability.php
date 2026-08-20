<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\SequenceInstance;
use App\Entity\User;
use App\Repository\ProgressionSequenceRepository;
use App\Repository\SequenceInstanceRepository;

/**
 * Which séquences of a class are still free to be planned - the single answer behind the "+ Ajouter
 * une séquence" select, the rail's "séquences non affectées" block, the creation screen's search,
 * and every write that takes a SequenceInstance id from a request.
 *
 * A SequenceInstance is planned ONCE. It is the class's frozen copy of a library template, so once a
 * progression carries it, it is affectée - for that progression and for every other one of the same
 * class. Asking only "does the progression I am looking at already carry it?" was the bug: the copy
 * disappeared from the progression that had planned it and went on being offered by the others, and
 * their rail still called it non affectée.
 *
 * Narrowed to the progression's own teachers rather than to whoever is looking: the class's pool is
 * shared between its teachers (see SequenceInstanceRepository::findForProgramCreatedByAny()), and a
 * staff member opening someone else's progression through ProgressionVoter's bypass has to see that
 * teacher's séquences - their own would be an empty list on a class they don't teach.
 *
 * "Teachers", plural, since co-animation: a progression's pool is its owner's instantiations plus
 * those of every co-animator named on it (design/validated/co-animation.md, lot 2). That widening
 * is about WHOSE copies are offered and nothing else - the once-per-class rule above is untouched,
 * and a séquence the co-animator instantiated but another progression already plans stays out of
 * both lists.
 */
class ProgressionSequenceAvailability
{
    public function __construct(
        private readonly SequenceInstanceRepository $sequenceInstanceRepository,
        private readonly ProgressionSequenceRepository $progressionSequenceRepository,
    ) {
    }

    /** @return list<SequenceInstance> */
    public function forProgression(Progression $progression): array
    {
        $program = $progression->getProgram();
        $teachers = $progression->getTeachers();

        if (null === $program || [] === $teachers) {
            return [];
        }

        return $this->forTeachers($program, $teachers);
    }

    /**
     * The pool of ONE teacher - what the creation screen asks, where there is no progression yet
     * and therefore no co-animator to widen it with.
     *
     * @return list<SequenceInstance>
     */
    public function forTeacher(Program $program, User $teacher): array
    {
        return $this->forTeachers($program, [$teacher]);
    }

    /**
     * @param list<User> $teachers
     *
     * @return list<SequenceInstance>
     */
    public function forTeachers(Program $program, array $teachers): array
    {
        $planned = array_flip($this->progressionSequenceRepository->findPlannedInstanceIdsForProgram($program));

        return array_values(array_filter(
            $this->sequenceInstanceRepository->findForProgramCreatedByAny($program, $teachers),
            static fn (SequenceInstance $instance): bool => !isset($planned[(int) $instance->getId()]),
        ));
    }

    /**
     * The write side of the lists above: may this progression plan, or delete, this séquence?
     *
     * Every action that takes a SequenceInstance id from a request goes through it, because the id
     * is the only thing a hand-built POST has to change to reach a colleague's copy - or one another
     * progression is already teaching. Filtering the <select> alone would make the rule cosmetic.
     */
    public function isAvailable(Progression $progression, SequenceInstance $instance): bool
    {
        $creator = $instance->getCreatedBy();

        // Read against the same set the lists are built from. A write side narrower than its list
        // would refuse a séquence the screen just offered, which is the mirror of the bug this
        // class was written for.
        $belongsToATeacherOfThePlan = null !== $creator
            && \in_array($creator, $progression->getTeachers(), true);

        return $belongsToATeacherOfThePlan
            && $instance->getProgram() === $progression->getProgram()
            && !$this->progressionSequenceRepository->isInstancePlanned($instance);
    }
}
