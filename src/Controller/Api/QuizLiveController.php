<?php

namespace App\Controller\Api;

use App\Entity\QuizLiveSession;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\QuizLiveParticipantRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Service\LiveSessionStateException;
use App\Service\QuizLiveSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mobile (student-only) counterpart to ProgramQuizLiveController/QuizLiveSessionService - join +
 * play a live multiplayer quiz from the Flutter app. No host/projector API in v1 - a teacher isn't
 * going to run the projector from their phone, see QuizLiveSessionService's class docblock.
 *
 * Unlike the web (native EventSource can't send custom headers, so App\Controller\
 * ProgramQuizLiveController uses the Mercure bundle's httpOnly-cookie mechanism instead), the
 * mobile client hand-rolls its own SSE parsing over a plain streamed HTTP request and sets a real
 * `Authorization: Bearer` header - so join() mints and returns a raw subscriber JWT here, one the
 * bundle's cookie flow never needs to expose on the web side.
 *
 * Class-level ROLE_STUDENT gate (the generic `^/api, roles: ROLE_USER` access_control in
 * security.yaml only requires authentication, not this specific role).
 */
#[IsGranted('ROLE_STUDENT')]
class QuizLiveController extends AbstractController
{
    #[Route(path: '/api/quiz-live/active', name: 'api_quiz_live_active', methods: ['GET'])]
    public function active(ProgramRepository $programRepository, QuizLiveSessionRepository $sessionRepository): JsonResponse
    {
        $program = $programRepository->findActiveForStudent($this->currentUser());
        $session = null !== $program ? $sessionRepository->findActiveForProgram($program) : null;

        if (null === $session) {
            return $this->json(['session' => null]);
        }

        return $this->json(['session' => [
            'sessionId' => $session->getId(),
            'name' => $session->getQuizInstance()->getName(),
            'programId' => $session->getQuizInstance()->getProgram()->getId(),
            'hostName' => $session->getHost()->getDisplayName() ?? $session->getHost()->getUsername(),
        ]]);
    }

    #[Route(path: '/api/quiz-live/{sessionId}/join', name: 'api_quiz_live_join', requirements: ['sessionId' => '\d+'], methods: ['POST'])]
    public function join(int $sessionId, Request $request, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService, HubInterface $mercureHub): JsonResponse
    {
        $student = $this->currentUser();
        $session = $this->findSessionOrNotFound($sessionId, $sessionRepository, $student);

        $data = json_decode($request->getContent(), true) ?? [];
        $displayName = trim((string) ($data['displayName'] ?? '')) ?: ($student->getDisplayName() ?? $student->getUsername());

        try {
            $participant = $liveSessionService->join($session, $student, $displayName);
        } catch (LiveSessionStateException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json([
            'participantId' => $participant->getId(),
            'mercurePublicUrl' => $mercureHub->getPublicUrl(),
            'mercureToken' => $liveSessionService->mintPlayerSubscriberToken($session),
            'playersTopic' => $liveSessionService->playersTopic($session),
            'state' => $liveSessionService->buildPlayerSnapshot($session),
        ]);
    }

    // Resync fallback (app backgrounded past the SSE connection's idle window, cold start, etc.) -
    // never the primary transport, same "resume from server truth" convention as
    // EcoRunnerApiController::state()'s own docblock.
    #[Route(path: '/api/quiz-live/{sessionId}/state', name: 'api_quiz_live_state', requirements: ['sessionId' => '\d+'], methods: ['GET'])]
    public function state(int $sessionId, QuizLiveSessionRepository $sessionRepository, QuizLiveParticipantRepository $participantRepository, QuizLiveSessionService $liveSessionService, HubInterface $mercureHub): JsonResponse
    {
        $student = $this->currentUser();
        $session = $this->findSessionOrNotFound($sessionId, $sessionRepository, $student);
        $participant = $participantRepository->findOneForStudent($session, $student);

        return $this->json([
            'participantId' => $participant?->getId(),
            'mercurePublicUrl' => $mercureHub->getPublicUrl(),
            'mercureToken' => $liveSessionService->mintPlayerSubscriberToken($session),
            'playersTopic' => $liveSessionService->playersTopic($session),
            'state' => $liveSessionService->buildPlayerSnapshot($session),
        ]);
    }

    #[Route(path: '/api/quiz-live/{sessionId}/answer', name: 'api_quiz_live_answer', requirements: ['sessionId' => '\d+'], methods: ['POST'])]
    public function answer(int $sessionId, Request $request, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): JsonResponse
    {
        $student = $this->currentUser();
        $session = $this->findSessionOrNotFound($sessionId, $sessionRepository, $student);

        $data = json_decode($request->getContent(), true) ?? [];
        $answerId = (int) ($data['answerId'] ?? 0);

        try {
            $liveSessionService->submitAnswer($session, $student, $answerId);
        } catch (LiveSessionStateException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json(['ok' => true]);
    }

    private function findSessionOrNotFound(int $sessionId, QuizLiveSessionRepository $repository, User $student): QuizLiveSession
    {
        $session = $repository->find($sessionId) ?? throw $this->createNotFoundException();

        if (!$session->getQuizInstance()->getProgram()->getStudents()->contains($student)) {
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
