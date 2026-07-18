<?php

namespace App\Controller;

use App\Entity\Program;
use App\Entity\QuizLiveSession;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\QuizLiveParticipantRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Service\LiveSessionStateException;
use App\Service\QuizLiveSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A student's live multiplayer quiz flow ("Concours live") - join (Turn 1r) then play (Turns
 * 1u/1h/1i/1j, student side). Route-level ROLE_STUDENT guards, same shape as
 * App\Controller\ProgramQuizAttemptController: reachability is decided by Program membership, not
 * a per-session audience.
 *
 * Discovery is code-less (see App\Repository\QuizLiveSessionRepository::findActiveForProgram()'s
 * docblock and the "Concours en cours" banner on program/quiz_mine.html.twig) - there is no
 * "type a room code" screen in v1.
 */
class ProgramQuizLiveController extends AbstractController
{
    #[Route(path: '/programs/{id}/quiz/live/{sessionId}/join', name: 'app_program_quiz_live_join', requirements: ['sessionId' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function join(int $id, int $sessionId, Request $request, ProgramRepository $repository, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);
        $student = $this->currentUser();

        if ('POST' === $request->getMethod()) {
            if (!$this->isCsrfTokenValid('quiz_live_join', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $displayName = trim((string) $request->request->get('displayName')) ?: ($student->getDisplayName() ?? $student->getUsername());

            try {
                $liveSessionService->join($session, $student, $displayName);
            } catch (LiveSessionStateException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('app_program_quiz_mine', ['id' => $program->getId()]);
            }

            return $this->redirectToRoute('app_program_quiz_live_play', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
        }

        return $this->render('program/quiz_live_join.html.twig', [
            'program' => $program,
            'session' => $session,
            'defaultDisplayName' => $student->getDisplayName() ?? $student->getUsername(),
        ]);
    }

    // Same cookie-based auth as QuizLiveHostController::projector() - see its docblock.
    #[Route(path: '/programs/{id}/quiz/live/{sessionId}/play', name: 'app_program_quiz_live_play', requirements: ['sessionId' => '\d+'])]
    #[IsGranted('ROLE_STUDENT')]
    public function play(int $id, int $sessionId, Request $request, ProgramRepository $repository, QuizLiveSessionRepository $sessionRepository, QuizLiveParticipantRepository $participantRepository, QuizLiveSessionService $liveSessionService, HubInterface $mercureHub, Authorization $mercureAuthorization): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        // The leaderboard payloads key rows by QuizLiveParticipant id, not User id - a student who
        // never joined (direct URL navigation, skipping /join) has no participant row yet and must
        // go through join() first, which is also where Program-membership is actually enforced.
        $participant = $participantRepository->findOneForStudent($session, $this->currentUser());
        if (null === $participant) {
            return $this->redirectToRoute('app_program_quiz_live_join', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
        }

        $mercureAuthorization->setCookie($request, [$liveSessionService->playersTopic($session)], [], [], 'subscriber');

        return $this->render('program/quiz_live_play.html.twig', [
            'program' => $program,
            'session' => $session,
            'participant' => $participant,
            'mercurePublicUrl' => $mercureHub->getPublicUrl(),
            'topic' => $liveSessionService->playersTopic($session),
            'initialState' => $liveSessionService->buildPlayerSnapshot($session),
            'answerUrl' => $this->generateUrl('app_program_quiz_live_answer', ['id' => $program->getId(), 'sessionId' => $session->getId()]),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/live/{sessionId}/answer', name: 'app_program_quiz_live_answer', requirements: ['sessionId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_STUDENT')]
    public function answer(int $id, int $sessionId, Request $request, ProgramRepository $repository, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $program = $this->findProgramForStudentOrNotFound($id, $repository);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        if (!$this->isCsrfTokenValid('quiz_live_answer', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $answerId = $request->request->getInt('answerId');

        try {
            $liveSessionService->submitAnswer($session, $this->currentUser(), $answerId);
        } catch (LiveSessionStateException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json(['ok' => true]);
    }

    private function findProgramForStudentOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $program;
    }

    private function findSessionOrNotFound(Program $program, int $sessionId, QuizLiveSessionRepository $repository): QuizLiveSession
    {
        $session = $repository->find($sessionId) ?? throw $this->createNotFoundException();

        if ($session->getQuizInstance()->getProgram()->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $session;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
