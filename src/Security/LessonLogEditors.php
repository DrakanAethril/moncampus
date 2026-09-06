<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\LessonSession;
use App\Entity\User;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgressionRepository;

/**
 * Who may write in one séance's cahier de texte: the teacher who delivers it, and their co-animator.
 *
 * A cahier de texte is an administrative record of what *that* teacher did with *that* group, so the
 * door is deliberately narrow - narrower than it used to be, since staff no longer walk through it
 * on the strength of their role alone (LessonLogVoter). Reading is untouched and stays wide.
 *
 * « Co-animator » is measured twice, because the platform records co-animation in two places and
 * neither subsumes the other:
 *
 *  - the **emploi du temps** knows the twin créneau - the other half of the same class, same
 *    matière, same hour, somebody else at the board (App\Service\LessonLogTwinRule). It is a fact,
 *    never a setting: if next week's import says otherwise, the answer follows on its own;
 *  - the **progression** knows the second formateur named on the plan (`Progression::$coTeachers`,
 *    design/validated/co-animation.md). Timetables that put the two groups on different days
 *    produce no twin at all, and this is what still calls that co-animation.
 *
 * The progression door only opens on a plan that *is* co-animated, and then to everyone named on it,
 * owner included. Restricting it to the co-teachers would make it one-way - the second formateur
 * could write in the owner's séance and the owner could not write in theirs - and opening it on a
 * solo plan would hand a colleague's créneau to whoever happens to own the Topic after a
 * reassignment.
 */
class LessonLogEditors
{
    public function __construct(
        private readonly LessonSessionRepository $sessions,
        private readonly ProgressionRepository $progressions,
    ) {
    }

    public function mayEdit(LessonSession $session, User $user): bool
    {
        // A créneau nobody holds has no « enseignant qui assure la séance » to let in. That is a gap
        // in the emploi du temps, and answering "anyone" would be the wrong way to close it.
        if (null === $session->getTeacher()) {
            return false;
        }

        if ($session->getTeacher() === $user) {
            return true;
        }

        return $this->isNamedOnTheCoAnimatedPlan($session, $user) || $this->holdsATwinOf($session, $user);
    }

    private function isNamedOnTheCoAnimatedPlan(LessonSession $session, User $user): bool
    {
        $topic = $session->getTopic();
        if (null === $topic) {
            return false;
        }

        $progression = $this->progressions->findOneForTopic($topic);

        return null !== $progression
            && $progression->isCoAnimated()
            && \in_array($user, $progression->getTeachers(), true);
    }

    private function holdsATwinOf(LessonSession $session, User $user): bool
    {
        foreach ($this->sessions->findTwinsOf($session) as $twin) {
            if ($twin->getTeacher() === $user) {
                return true;
            }
        }

        return false;
    }
}
