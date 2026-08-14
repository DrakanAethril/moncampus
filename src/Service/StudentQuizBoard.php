<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Repository\QuizInstanceRepository;

/**
 * "Which quizzes may this student see, and which may they start?" - the single answer, for the web
 * hub (App\Controller\ProgramQuizAttemptController) and its mobile twin
 * (App\Controller\Api\QuizController) alike.
 *
 * It exists because those two screens did not ask. A QuizInstance has been an AccessConditionHost
 * since the conditions shipped and the teacher's screen has offered « Conditions d'accès » on it
 * ever since, but nothing between the stored rule and the student ever consulted
 * App\Service\AccessConditionGate: the condition was saved, displayed, and did nothing. A lock that
 * does not lock is worse than no lock, because the teacher believes they set it.
 *
 * A service rather than two calls in two controllers, for the reason StudentWorkBoard's docblock
 * gives about its own screens: a rule applied on one side only is how two screens come to announce
 * different things. This is that rule's only home.
 */
class StudentQuizBoard
{
    public function __construct(
        private readonly QuizInstanceRepository $instanceRepository,
        private readonly AccessConditionGate $accessGate,
    ) {
    }

    /**
     * The student's quizzes for one program: deactivated ones already excluded by the repository,
     * then the gate's own two-step - an « Invisible » quiz leaves the list entirely, a « Grisé » one
     * stays with the way out written on it.
     */
    public function readableFor(Program $program, User $reader, ?\DateTimeImmutable $now = null): StudentQuizReadableInstances
    {
        $instances = $this->instanceRepository->findActiveForProgram($program);
        $verdicts = $this->accessGate->verdicts($instances, $reader, $now);

        return new StudentQuizReadableInstances($verdicts->visibleOnly($instances), $verdicts);
    }

    /**
     * May this student actually start it?
     *
     * Asked again at the door rather than trusted from the list, and for a concrete reason: a greyed
     * row names its quiz, so its address is one click away from being typed by hand. This is the
     * same belt the conditions already wear on a resource's open action.
     */
    public function isOpenFor(QuizInstance $instance, User $reader, ?\DateTimeImmutable $now = null): bool
    {
        return $this->accessGate->isOpen($instance, $reader, $now);
    }
}
