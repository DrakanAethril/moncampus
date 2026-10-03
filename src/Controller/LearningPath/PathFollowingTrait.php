<?php

declare(strict_types=1);

namespace App\Controller\LearningPath;

use App\Entity\LearningPath;
use App\Entity\LearningPathEnrollment;
use App\Entity\User;
use App\Enum\LearningPathStepState;
use App\Repository\LearningPathEnrollmentRepository;
use App\Repository\LearningPathRepository;
use App\Security\Voter\LearningPathVoter;
use App\Service\LearningPath\LearningPathBoard;
use App\Service\LearningPath\LearningPathProgress;
use App\Service\LearningPath\LearningPathStepView;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * What the screens of somebody following a learning path share: finding the path, and **asking the
 * rule again at the door of a step** (design/validated/cours-en-ligne.md, §10). A greyed row of the
 * plan names its step, so its address is one click from being typed: the plan showing a lock is not
 * what keeps a step closed, this is.
 */
trait PathFollowingTrait
{
    private function findPath(int $id, LearningPathRepository $paths): LearningPath
    {
        $path = $paths->find($id);
        if (null === $path || !$this->isGranted(LearningPathVoter::FOLLOW, $path)) {
            throw $this->createNotFoundException();
        }

        return $path;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    /**
     * The step somebody asks for, as they may open it - or the way back to the plan, with the
     * reason: not started, unavailable, or closed by a quiz that names itself.
     *
     * @return array{LearningPathEnrollment, LearningPathProgress, LearningPathStepView}|RedirectResponse
     */
    private function stepOrRedirect(LearningPath $path, int $number, LearningPathEnrollmentRepository $enrollments, LearningPathBoard $board): array|RedirectResponse
    {
        $plan = $this->redirectToRoute('app_learning_path_show', ['id' => $path->getId()]);

        $enrollment = $enrollments->findOneFor($path, $this->currentUser());
        if (null === $enrollment) {
            $this->addFlash('info', 'learningPathStartFirstFlashMessage');

            return $plan;
        }

        $progress = $board->progress($path, $enrollment);
        $view = $progress->viewOf($number);
        if (null === $view) {
            throw $this->createNotFoundException();
        }

        if (LearningPathStepState::Unavailable === $view->state) {
            $this->addFlash('info', 'learningPathStepUnavailableFlashMessage');

            return $plan;
        }

        if (LearningPathStepState::Locked === $view->state) {
            $this->addFlash('error', 'learningPathStepLockedFlashMessage');

            return $plan;
        }

        return [$enrollment, $progress, $view];
    }
}
