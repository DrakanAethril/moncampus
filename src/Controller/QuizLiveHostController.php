<?php

namespace App\Controller;

use App\Entity\Program;
use App\Entity\QuizLiveSession;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\QuizLiveParticipantRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Repository\QuizTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Service\LiveSessionStateException;
use App\Service\LiveTemplateNotEligibleException;
use App\Service\QuizLiveSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Teacher-facing live multiplayer quiz control ("Concours live") - see
 * design/design_campus_manager/reference/Générateur de quiz.dc.html, Turns 1t (created)/1q
 * (waiting room)/1u (countdown)/1h (question)/1i (reveal+classement)/1j (podium). A deliberately
 * separate CTA from App\Controller\QuizLibraryController::launch() (screen 1c's form): that form's
 * window dates/draw-recipe/fairness toggles are entirely inapplicable to Live mode, which plays
 * every question in order, synchronized for everyone - see QuizLiveSessionService's class docblock.
 *
 * Gated the same way as QuizLibraryController for template ownership, and like ProgramQuizController
 * (StructureAccessChecker::isProgramTeacher()) for the Program side, since a session is reachable
 * by any teacher of the target Program, not just whoever happened to create it.
 */
class QuizLiveHostController extends AbstractController
{
    #[Route(path: '/programs/{id}/quiz/live/new', name: 'app_program_quiz_live_new')]
    public function new(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizTemplateRepository $templateRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/quiz_live_new.html.twig', [
            'program' => $program,
            'templates' => $templateRepository->findForTeacher($this->currentUser()),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/live/create', name: 'app_program_quiz_live_create', methods: ['POST'])]
    public function create(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizTemplateRepository $templateRepository, QuizLiveSessionService $liveSessionService, TranslatorInterface $translator): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        if (!$this->isCsrfTokenValid('quiz_live_create', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $template = $this->findTemplateOrNotFound($templateRepository, $request->request->getInt('templateId'));

        // The field is optional (placeholder-only, no default value) - a blank submit is an empty
        // string, which InputBag::getInt() rejects outright rather than treating as absent.
        $secondsPerQuestionRaw = trim((string) $request->request->get('secondsPerQuestion', ''));
        $secondsPerQuestion = ctype_digit($secondsPerQuestionRaw) ? (int) $secondsPerQuestionRaw : 0;

        try {
            $session = $liveSessionService->createSession($template, $program, $this->currentUser(), $secondsPerQuestion > 0 ? $secondsPerQuestion : null);
        } catch (LiveTemplateNotEligibleException $exception) {
            $this->addFlash('error', $translator->trans('quizLiveNotEligibleFlashMessage', [
                '%questions%' => implode(', ', $exception->getOffendingQuestionLabels()),
            ]));

            return $this->redirectToRoute('app_program_quiz_live_new', ['id' => $program->getId()]);
        }

        return $this->redirectToRoute('app_program_quiz_live_created', ['id' => $program->getId(), 'sessionId' => $session->getId()]);
    }

    #[Route(path: '/programs/{id}/quiz/live/{sessionId}', name: 'app_program_quiz_live_created', requirements: ['sessionId' => '\d+'])]
    public function created(int $id, int $sessionId, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        return $this->render('program/quiz_live_created.html.twig', [
            'program' => $program,
            'session' => $session,
        ]);
    }

    // Native EventSource can't send custom headers, so unlike the mobile API (a raw JWT the
    // Flutter client puts in an Authorization header, see Api\QuizLiveController) the web side
    // uses the bundle's cookie mechanism: Authorization::setCookie() scopes an httpOnly cookie to
    // exactly this topic (signed with the *subscriber* hub's own secret - see
    // config/packages/mercure.yaml), and the SetCookieSubscriber kernel listener attaches it to
    // the response. The template then just opens `new EventSource(url, {withCredentials: true})` -
    // no token ever touches page JS.
    #[Route(path: '/programs/{id}/quiz/live/{sessionId}/projector', name: 'app_program_quiz_live_projector', requirements: ['sessionId' => '\d+'])]
    public function projector(int $id, int $sessionId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService, HubInterface $mercureHub, Authorization $mercureAuthorization): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        $mercureAuthorization->setCookie($request, [$liveSessionService->hostTopic($session)], [], [], 'subscriber');

        return $this->render('program/quiz_live_projector.html.twig', [
            'program' => $program,
            'session' => $session,
            'mercurePublicUrl' => $mercureHub->getPublicUrl(),
            'topic' => $liveSessionService->hostTopic($session),
            'initialState' => $liveSessionService->buildHostSnapshot($session),
            'advanceUrl' => $this->generateUrl('app_program_quiz_live_advance', ['id' => $program->getId(), 'sessionId' => $session->getId()]),
            'cancelUrl' => $this->generateUrl('app_program_quiz_live_cancel', ['id' => $program->getId(), 'sessionId' => $session->getId()]),
            'finishUrl' => $this->generateUrl('app_program_quiz_live_finish', ['id' => $program->getId(), 'sessionId' => $session->getId()]),
            'resultUrl' => $this->generateUrl('app_program_quiz_live_history', ['id' => $program->getId()]),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/live/{sessionId}/advance', name: 'app_program_quiz_live_advance', requirements: ['sessionId' => '\d+'], methods: ['POST'])]
    public function advance(int $id, int $sessionId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        if (!$this->isCsrfTokenValid('quiz_live_control', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            // start() and advance() are two service methods but one button (Lancer/Suivant/Question
            // suivante) - the controller picks which to call based on Lobby vs everything else.
            if ('lobby' === $session->getStatus()->value) {
                $liveSessionService->start($session, $this->currentUser());
            } else {
                $liveSessionService->advance($session, $this->currentUser());
            }
        } catch (LiveSessionStateException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json(['ok' => true]);
    }

    // Plain redirect, not JSON - unlike advance() this always ends the interactive session (called
    // both from the static "created" page, before the projector is even open, and from the
    // projector's own "Annuler la session" button), so a full navigation is simpler and more
    // robust than fetch()-then-JS-redirect.
    #[Route(path: '/programs/{id}/quiz/live/{sessionId}/cancel', name: 'app_program_quiz_live_cancel', requirements: ['sessionId' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, int $sessionId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        if (!$this->isCsrfTokenValid('quiz_live_control', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $liveSessionService->cancel($session, $this->currentUser());
            $this->addFlash('success', 'quizLiveCancelledFlashMessage');
        } catch (LiveSessionStateException) {
            $this->addFlash('error', 'quizLiveCancelFailedFlashMessage');
        }

        return $this->redirectToRoute('app_program_quiz', ['id' => $program->getId()]);
    }

    // Plain redirect, same reasoning as cancel() above.
    #[Route(path: '/programs/{id}/quiz/live/{sessionId}/finish', name: 'app_program_quiz_live_finish', requirements: ['sessionId' => '\d+'], methods: ['POST'])]
    public function finish(int $id, int $sessionId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        if (!$this->isCsrfTokenValid('quiz_live_control', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $liveSessionService->finish($session, $this->currentUser());

        return $this->redirectToRoute('app_program_quiz_live_history', ['id' => $program->getId()]);
    }

    // Turn 1o - archive of finished/cancelled sessions, the Live-mode counterpart of
    // ProgramQuizController::list()/show() (which deliberately excludes Live instances entirely -
    // see QuizInstanceRepository::findForProgram()'s docblock).
    #[Route(path: '/programs/{id}/quiz/live-sessions', name: 'app_program_quiz_live_history')]
    public function history(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository, QuizLiveParticipantRepository $participantRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $sessions = $sessionRepository->findFinishedForProgram($program);

        $rows = array_map(static fn (QuizLiveSession $session): array => [
            'session' => $session,
            'podium' => \array_slice($participantRepository->findRankedForSession($session), 0, 3),
        ], $sessions);

        return $this->render('program/quiz_live_history.html.twig', [
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/live-sessions/{sessionId}', name: 'app_program_quiz_live_result', requirements: ['sessionId' => '\d+'])]
    public function result(int $id, int $sessionId, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository, QuizLiveParticipantRepository $participantRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        return $this->render('program/quiz_live_result.html.twig', [
            'program' => $program,
            'session' => $session,
            'ranked' => $participantRepository->findRankedForSession($session),
        ]);
    }

    #[Route(path: '/programs/{id}/quiz/live-sessions/{sessionId}/delete', name: 'app_program_quiz_live_delete', requirements: ['sessionId' => '\d+'], methods: ['POST'])]
    public function delete(int $id, int $sessionId, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, QuizLiveSessionRepository $sessionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $session = $this->findSessionOrNotFound($program, $sessionId, $sessionRepository);

        if (!$this->isCsrfTokenValid('quiz_live_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Only a terminal session can be deleted - an active one must go through cancel()/finish()
        // first, same "no shortcuts around the state machine" reasoning as every other guard here.
        if (!\in_array($session->getStatus()->value, ['finished', 'cancelled'], true)) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($session);
        $entityManager->flush();

        $this->addFlash('success', 'quizLiveHistoryDeletedFlashMessage');

        return $this->redirectToRoute('app_program_quiz_live_history', ['id' => $program->getId()]);
    }

    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    private function findTemplateOrNotFound(QuizTemplateRepository $repository, int $id): QuizTemplate
    {
        $template = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($template->getTeacher() !== $this->currentUser() && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_STAFF-LEAD')) {
            throw $this->createNotFoundException();
        }

        return $template;
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
