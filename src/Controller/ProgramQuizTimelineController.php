<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\QuizAttempt;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\QuizReviewOutcome;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\QuizInstanceRepository;
use App\Security\StructureAccessChecker;
use App\Service\PostValue;
use App\Service\QuizSupervisionReportBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The teacher's side of the mode contrôle: the timeline of one copy, and the decision taken about it.
 *
 * The timeline is the real deliverable of the whole design - a dated sentence one can put in front
 * of a student. « À 4′12 tu as quitté la page 38 secondes pendant la question 7, tu es revenu, tu as
 * répondu juste - explique-moi » defends itself; « le système t'a mis zéro » does not, and is never
 * said here: nothing on this screen changes a mark.
 *
 * Same door as the rest of a launched quiz's results, StructureAccessChecker::isProgramTeacher():
 * the teachers of the formation and the staff. A student never reaches it - reading who was flagged
 * is not theirs, and the rule's whole value is that it is read by a person who can ask.
 */
#[RequiresFeature(Feature::QuizLibrary)]
class ProgramQuizTimelineController extends AbstractController
{
    #[Route(
        path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/timeline',
        name: 'app_program_quiz_timeline',
        requirements: ['id' => '\d+', 'instanceId' => '\d+', 'attemptId' => '\d+'],
    )]
    public function timeline(
        int $id,
        int $instanceId,
        int $attemptId,
        ProgramRepository $repository,
        StructureAccessChecker $accessChecker,
        QuizInstanceRepository $instanceRepository,
        QuizAttemptRepository $attemptRepository,
        QuizSupervisionReportBuilder $reportBuilder,
    ): Response {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        // The rule is re-read here rather than trusted from the stored count: the count classes the
        // list, the reading is what is shown, and the two must not be able to disagree.
        $report = $reportBuilder->build($attempt);

        return $this->render('program/quiz_timeline.html.twig', [
            'program' => $program,
            'quizInstance' => $instance,
            'attempt' => $attempt,
            'report' => $report,
            'rows' => $reportBuilder->timelineRows($attempt, $report),
        ]);
    }

    /**
     * « Écarter » / « Retenir et transmettre ». The only thing in the device that asserts something
     * about somebody, and it is signed by whoever pressed the button.
     */
    #[Route(
        path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/review',
        name: 'app_program_quiz_review',
        requirements: ['id' => '\d+', 'instanceId' => '\d+', 'attemptId' => '\d+'],
        methods: ['POST'],
    )]
    public function review(
        int $id,
        int $instanceId,
        int $attemptId,
        Request $request,
        EntityManagerInterface $entityManager,
        ProgramRepository $repository,
        StructureAccessChecker $accessChecker,
        QuizInstanceRepository $instanceRepository,
        QuizAttemptRepository $attemptRepository,
    ): Response {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $instance = $this->findInstanceOrNotFound($instanceRepository, $program, $instanceId);
        $attempt = $this->findAttemptOrNotFound($attemptRepository, $instance, $attemptId);

        if (!$this->isCsrfTokenValid('quiz_supervision_review', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $outcome = QuizReviewOutcome::tryFrom(PostValue::string($request, 'outcome')) ?? throw $this->createNotFoundException();
        $attempt->review($outcome, $this->currentUser(), PostValue::string($request, 'note'));
        $entityManager->flush();

        $this->addFlash('success', 'quizSupervisionReviewedFlashMessage');

        return $this->redirectToRoute('app_program_quiz_timeline', [
            'id' => $program->getId(),
            'instanceId' => $instance->getId(),
            'attemptId' => $attempt->getId(),
        ]);
    }

    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    private function findInstanceOrNotFound(QuizInstanceRepository $repository, Program $program, int $instanceId): QuizInstance
    {
        $instance = $repository->find($instanceId) ?? throw $this->createNotFoundException();

        if ($instance->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        // A timeline only exists where the passation was announced as supervised. Reading one on a
        // quiz that never said so would be reading a journal nobody was told about.
        if (!$instance->isSupervised()) {
            throw $this->createNotFoundException();
        }

        return $instance;
    }

    private function findAttemptOrNotFound(QuizAttemptRepository $repository, QuizInstance $instance, int $attemptId): QuizAttempt
    {
        $attempt = $repository->find($attemptId) ?? throw $this->createNotFoundException();

        if ($attempt->getQuizInstance()->getId() !== $instance->getId()) {
            throw $this->createNotFoundException();
        }

        return $attempt;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
