<?php

declare(strict_types=1);

namespace App\Controller\QuizLive;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\QuizFolder;
use App\Entity\QuizLiveSession;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\LiveSessionStatus;
use App\Repository\ProgramRepository;
use App\Repository\QuizFolderRepository;
use App\Repository\QuizLiveParticipantRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Repository\QuizTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\QuizLiveSessionVoter;
use App\Service\JsonRequestPayload;
use App\Service\LibraryPickerTree;
use App\Service\LiveSessionStateException;
use App\Service\LiveTemplateNotEligibleException;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\QuizLive\LiveQuestionAnalysis;
use App\Service\QuizLive\LiveResultSheet;
use App\Service\QuizLive\LiveScreenLabels;
use App\Service\QuizLive\LiveThemeCatalog;
use App\Service\QuizLiveSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outils › Concours live, the host's side (design/validated/quiz-live-public.md): the contests one
 * runs or teaches, a new one - of a class or of none, public or not, in a theme -, its projector and
 * its results. Since 2026-11-07 a contest is no longer reached through a class: a class is a filter of
 * the list, and the class's own screens link here with it set.
 *
 * Who runs and reads a contest is QuizLiveSessionVoter's: its host, the teachers of its class, staff
 * for a class contest. A refusal is a 404. Playing is App\Controller\QuizLive\PlayController's.
 */
#[RequiresFeature(Feature::QuizLive)]
class HostController extends AbstractController
{
    private const array HOSTS = ['ROLE_TEACHER', 'ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    #[Route(path: '/quiz-live', name: 'app_quiz_live_index', methods: ['GET'])]
    public function index(Request $request, ProgramRepository $programRepository, QuizLiveSessionRepository $sessionRepository, QuizLiveParticipantRepository $participantRepository): Response
    {
        $user = $this->host();
        $programs = $programRepository->findAllForTeacher($user);

        // `?class=` narrows the list: a class one teaches, or « none ». An unknown value is the
        // whole list - a filter never 404s.
        $filter = QueryValue::trimmed($request, 'class');
        $only = null;
        foreach ($programs as $program) {
            if ((string) $program->getId() === $filter) {
                $only = $program;
            }
        }
        $classless = 'none' === $filter;

        $sessions = $sessionRepository->findForHost($user, $programs, $only, $classless);
        $rows = array_map(static fn (QuizLiveSession $session): array => [
            'session' => $session,
            // A running contest has no podium worth showing; its row leads back to the projector.
            'podium' => $session->isOver() ? \array_slice($participantRepository->findRankedForSession($session), 0, 3) : [],
        ], $sessions);

        return $this->render('quiz_live/index.html.twig', [
            'rows' => $rows,
            'programs' => $programs,
            'filter' => null !== $only ? (string) $only->getId() : ($classless ? 'none' : ''),
            'filteredProgram' => $only,
        ]);
    }

    #[Route(path: '/quiz-live/new', name: 'app_quiz_live_new', methods: ['GET'])]
    public function new(Request $request, ProgramRepository $programRepository, QuizTemplateRepository $templateRepository, QuizFolderRepository $folders, LibraryPickerTree $pickerTree, LiveThemeCatalog $themes): Response
    {
        $user = $this->host();
        $programs = $programRepository->findAllForTeacher($user);
        $templates = $templateRepository->findForTeacher($user);
        $preselected = QueryValue::nullableInt($request, 'program');

        return $this->render('quiz_live/new.html.twig', [
            'programs' => $programs,
            'preselectedProgramId' => $preselected,
            'templates' => $templates,
            'themes' => $themes,
            'today' => new \DateTimeImmutable(),
            // Feeds quiz_pool_controller.js's running total. Counted the same way the select
            // labels are (every question, not only the live-playable ones), so the total the host
            // reads is the sum of the numbers in front of them. `defaultCount` is inert here - a
            // live contest plays the whole pool and draws nothing - but the shape is shared.
            'questionCountsByTemplate' => array_combine(
                array_map(static fn (QuizTemplate $template): int => (int) $template->getId(), $templates),
                array_map(static fn (QuizTemplate $template): array => ['questions' => $template->getQuestions()->count(), 'defaultCount' => 0], $templates),
            ),
            // The library as a tree, for the picker modal the base field and the merge rows open -
            // the same one « Lancer un quiz » uses. A host picks from their own library.
            'pickerTree' => $pickerTree->build(
                array_map(
                    static fn (QuizFolder $folder): array => [
                        'id' => (int) $folder->getId(),
                        'parentId' => $folder->getParent()?->getId(),
                        'name' => $folder->getName(),
                    ],
                    $folders->findAllFor($user),
                ),
                array_map(
                    static fn (QuizTemplate $template): array => [
                        'id' => (int) $template->getId(),
                        'folderId' => $template->getFolder()?->getId(),
                        'label' => $template->getName() ?? '',
                        'count' => $template->getQuestions()->count(),
                    ],
                    $templates,
                ),
            ),
        ]);
    }

    /**
     * The form's, and the virtual board's (App\Service\ClassBoard\ClassBoardLiveData), which posts the
     * quiz alone with the class in the query string - and reads the address it lands on.
     */
    #[Route(path: '/quiz-live/create', name: 'app_quiz_live_create', methods: ['POST'])]
    public function create(Request $request, ProgramRepository $programRepository, StructureAccessChecker $accessChecker, QuizTemplateRepository $templateRepository, QuizLiveSessionService $liveSessionService, LiveThemeCatalog $themes, TranslatorInterface $translator): Response
    {
        $user = $this->host();

        if (!$this->isCsrfTokenValid('quiz_live_create', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The class is optional; one named must be one the host teaches.
        $programId = PostValue::int($request, 'programId');
        if ($programId <= 0) {
            $programId = QueryValue::int($request, 'program');
        }
        $program = null;
        if ($programId > 0) {
            $program = $programRepository->find($programId);
            if (!$program instanceof Program || !$accessChecker->isProgramTeacher($program)) {
                throw $this->createNotFoundException();
            }
        }
        $back = $this->redirectToRoute('app_quiz_live_new', null !== $program ? ['program' => $program->getId()] : []);

        // No quiz chosen is a thing the host can do - the field opens on a placeholder now that it
        // is picked in a modal - and it is not a 404: nothing is missing, something is unanswered.
        $templateId = PostValue::int($request, 'templateId');
        if ($templateId <= 0) {
            $this->addFlash('error', $translator->trans('quizLaunchTemplateRequiredError'));

            return $back;
        }

        // Extra quizzes merged into the same pool, in the order the host added them - each still
        // goes through findTemplateOrNotFound(), so a hand-crafted id for someone else's quiz is
        // rejected exactly like the base one. A row left on its placeholder posts an empty string.
        $templates = [$this->findTemplateOrNotFound($templateRepository, $templateId)];
        foreach (JsonRequestPayload::fromArray($request->request->all())->ids('additionalTemplateIds') as $additionalId) {
            if ($additionalId <= 0) {
                continue;
            }
            $additional = $this->findTemplateOrNotFound($templateRepository, $additionalId);
            if (!\in_array($additional, $templates, true)) {
                $templates[] = $additional;
            }
        }

        $name = trim((string) $request->request->get('name', ''));
        // The field is optional - a blank submit is an empty string, which InputBag::getInt()
        // rejects outright rather than treating as absent.
        $secondsRaw = trim((string) $request->request->get('secondsPerQuestion', ''));
        $seconds = ctype_digit($secondsRaw) ? (int) $secondsRaw : 0;
        // A contest of no class is public whatever the box says (QuizLiveSession's constructor).
        $public = $request->request->getBoolean('public');
        $theme = $themes->resolve(trim((string) $request->request->get('theme', '')))->slug;

        try {
            $session = $liveSessionService->createSession($templates, $program, $user, $seconds > 0 ? $seconds : null, '' !== $name ? $name : null, $public, $theme);
        } catch (LiveTemplateNotEligibleException) {
            $this->addFlash('error', $translator->trans('quizLiveNoPlayableQuestionFlashMessage'));

            return $back;
        }

        // Left out rather than refused: a texte à trous or a zone question has no place in the
        // room, and the rest of the pool still plays. The host is told which.
        $skipped = $liveSessionService->skippedQuestions($templates);
        if ([] !== $skipped) {
            $this->addFlash('warning', $translator->trans('quizLiveSkippedQuestionsFlashMessage', [
                '%count%' => \count($skipped),
                '%questions%' => implode(' · ', \array_slice($skipped, 0, 5)).(\count($skipped) > 5 ? ' …' : ''),
            ]));
        }

        return $this->redirectToRoute('app_quiz_live_created', ['id' => $session->getId()]);
    }

    #[Route(path: '/quiz-live/{id}', name: 'app_quiz_live_created', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function created(int $id, QuizLiveSessionRepository $sessionRepository, LiveThemeCatalog $themes): Response
    {
        $session = $this->managed($id, $sessionRepository);

        return $this->render('quiz_live/created.html.twig', [
            'session' => $session,
            'theme' => $themes->resolve($session->getTheme()),
            'joinUrl' => $this->joinUrl($session),
        ]);
    }

    // Native EventSource can't send custom headers, so unlike the mobile API (a raw JWT the Flutter
    // client puts in an Authorization header, see Api\QuizLiveController) the web side uses the
    // bundle's cookie mechanism: Authorization::setCookie() scopes an httpOnly cookie to exactly this
    // topic (signed with the *subscriber* hub's own secret - see config/packages/mercure.yaml).
    #[Route(path: '/quiz-live/{id}/projector', name: 'app_quiz_live_projector', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projector(int $id, Request $request, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService, LiveThemeCatalog $themes, LiveScreenLabels $labels, HubInterface $mercureHub): Response
    {
        $session = $this->managed($id, $sessionRepository);
        $liveSessionService->subscribe($request, $liveSessionService->hostTopic($session));
        $joinUrl = $this->joinUrl($session);

        return $this->render('quiz_live/projector.html.twig', [
            'session' => $session,
            'theme' => $themes->resolve($session->getTheme()),
            'mercurePublicUrl' => $mercureHub->getPublicUrl(),
            'topic' => $liveSessionService->hostTopic($session),
            'initialState' => $liveSessionService->buildHostSnapshot($session),
            'context' => [
                'quizName' => (string) $session->getQuizInstance()?->getName(),
                'public' => $session->isPublic(),
                'code' => $session->getRoomCode(),
                // What the room reads and types: the address without its scheme, the code apart.
                'joinLabel' => (string) preg_replace('#^https?://#', '', (string) preg_replace('#/[^/]+$#', '', $joinUrl)),
                // The QR code is drawn here, once: the waiting room shows it whatever the network does.
                'qrSvg' => $session->isPublic() ? $this->qrSvg($joinUrl) : null,
                'className' => $session->getProgram()?->getDisplayShortName(),
                'cancelUrl' => $this->generateUrl('app_quiz_live_cancel', ['id' => $session->getId()]),
                'cancelToken' => $this->container->get('security.csrf.token_manager')->getToken('quiz_live_control')->getValue(),
                'resultsUrl' => $this->generateUrl('app_quiz_live_results', ['id' => $session->getId()]),
                'indexUrl' => $this->generateUrl('app_quiz_live_index'),
                'labels' => $labels->all(),
            ],
        ]);
    }

    /**
     * The board's phase as it is now - asked again when the projector's stream reconnects after a
     * network drop, since the hub replays none of the events published meanwhile.
     */
    #[Route(path: '/quiz-live/{id}/projector/state', name: 'app_quiz_live_projector_state', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projectorState(int $id, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        return $this->json(['state' => $liveSessionService->buildHostSnapshot($this->managed($id, $sessionRepository))]);
    }

    #[Route(path: '/quiz-live/{id}/advance', name: 'app_quiz_live_advance', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function advance(int $id, Request $request, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $session = $this->managed($id, $sessionRepository);
        if (!$this->isCsrfTokenValid('quiz_live_control', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The step the screen was showing when it asked: a click and the screen's own timer arriving
        // together must move the room once (QuizLiveSessionService::advance()).
        $payload = JsonRequestPayload::fromRequest($request);
        $expectedStatus = LiveSessionStatus::tryFrom($payload->string('from'));
        $expectedIndex = $payload->int('questionIndex');

        try {
            // start() and advance() are two service methods but one button: Lobby starts the room.
            if (LiveSessionStatus::Lobby === $session->getStatus()) {
                $liveSessionService->start($session, $this->host());
            } else {
                $liveSessionService->advance($session, $this->host(), $expectedStatus, $expectedIndex);
            }
        } catch (LiveSessionStateException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json(['ok' => true]);
    }

    /** The host takes a player out of the waiting room (QuizLiveSessionService::remove()). */
    #[Route(path: '/quiz-live/{id}/remove', name: 'app_quiz_live_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(int $id, Request $request, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $session = $this->managed($id, $sessionRepository);
        if (!$this->isCsrfTokenValid('quiz_live_control', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $liveSessionService->remove($session, JsonRequestPayload::fromRequest($request)->int('participantId'));
        } catch (LiveSessionStateException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json(['ok' => true]);
    }

    // Plain redirect, not JSON - this always ends the interactive session (called both from the
    // « created » page and from the projector's own button).
    #[Route(path: '/quiz-live/{id}/cancel', name: 'app_quiz_live_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $session = $this->managed($id, $sessionRepository);
        if (!$this->isCsrfTokenValid('quiz_live_control', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $liveSessionService->cancel($session, $this->host());
            $this->addFlash('success', 'quizLiveCancelledFlashMessage');
        } catch (LiveSessionStateException) {
            $this->addFlash('error', 'quizLiveCancelFailedFlashMessage');
        }

        return $this->redirectToRoute('app_quiz_live_index');
    }

    #[Route(path: '/quiz-live/{id}/finish', name: 'app_quiz_live_finish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function finish(int $id, Request $request, QuizLiveSessionRepository $sessionRepository, QuizLiveSessionService $liveSessionService): Response
    {
        $session = $this->managed($id, $sessionRepository);
        if (!$this->isCsrfTokenValid('quiz_live_control', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $liveSessionService->finish($session, $this->host());

        return $this->redirectToRoute('app_quiz_live_results', ['id' => $session->getId()]);
    }

    /**
     * Every player's answers, account or guest, question by question - what a contest keeps
     * (LiveResultSheet) -, then the questions' success rates and the votes' results
     * (LiveQuestionAnalysis), the « Sondages » tab only when the contest carried a vote.
     */
    #[Route(path: '/quiz-live/{id}/results', name: 'app_quiz_live_results', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function results(int $id, Request $request, QuizLiveSessionRepository $sessionRepository, LiveResultSheet $sheet, LiveQuestionAnalysis $analysis, LiveThemeCatalog $themes): Response
    {
        $session = $this->managed($id, $sessionRepository);
        $sort = QueryValue::trimmed($request, 'sort');
        $sort = \in_array($sort, LiveQuestionAnalysis::SORTS, true) ? $sort : 'rate_asc';
        $analysed = $analysis->of($session, $sort);
        $tabs = [] === $analysed['polls'] ? ['accounts', 'guests', 'questions'] : ['accounts', 'guests', 'questions', 'polls'];
        $tab = QueryValue::trimmed($request, 'show');

        return $this->render('quiz_live/results.html.twig', [
            'session' => $session,
            'theme' => $themes->resolve($session->getTheme()),
            'sheet' => $sheet->of($session),
            'analysis' => $analysed,
            'sort' => $sort,
            'tab' => \in_array($tab, $tabs, true) ? $tab : 'all',
        ]);
    }

    #[Route(path: '/quiz-live/{id}/delete', name: 'app_quiz_live_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, QuizLiveSessionRepository $sessionRepository, EntityManagerInterface $entityManager): Response
    {
        $session = $this->managed($id, $sessionRepository);
        if (!$this->isCsrfTokenValid('quiz_live_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Only a contest that is over: a running one is on somebody's projector.
        if (!$session->isOver()) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($session);
        $entityManager->flush();
        $this->addFlash('success', 'quizLiveHistoryDeletedFlashMessage');

        return $this->redirectToRoute('app_quiz_live_index');
    }

    /**
     * Every theme, with captures of the real screens drawn from a sample contest by the screens' own
     * scripts (assets/quiz_live/) - adding a theme adds its captures, nothing to photograph.
     */
    #[Route(path: '/quiz-live/themes', name: 'app_quiz_live_themes', methods: ['GET'])]
    public function themes(Request $request, LiveThemeCatalog $themes, LiveScreenLabels $labels, TranslatorInterface $translator): Response
    {
        $this->host();
        $show = QueryValue::trimmed($request, 'show');

        return $this->render('quiz_live/themes.html.twig', [
            'themes' => $themes,
            'show' => \in_array($show, ['permanent', 'seasonal'], true) ? $show : 'all',
            'today' => new \DateTimeImmutable(),
            'labels' => $labels->all(),
            // The guest page's capture is the page itself, for a contest that does not exist.
            'sample' => ['id' => 0, 'roomCode' => 'DEMO42', 'quizInstance' => ['name' => $translator->trans('quizLiveThemesSampleQuizName')]],
            'sampleQr' => $this->qrSvg($this->generateUrl('app_quiz_live_entry', ['code' => 'DEMO42'], UrlGeneratorInterface::ABSOLUTE_URL)),
        ]);
    }

    /** The class's own entries, kept for the links and bookmarks that name them. */
    #[Route(path: '/programs/{id}/quiz/live-sessions', name: 'app_program_quiz_live_history', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function programHistory(int $id): Response
    {
        return $this->redirectToRoute('app_quiz_live_index', ['class' => $id]);
    }

    #[Route(path: '/programs/{id}/quiz/live/new', name: 'app_program_quiz_live_new', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function programNew(int $id): Response
    {
        return $this->redirectToRoute('app_quiz_live_new', ['program' => $id]);
    }

    /** The QR code as inline SVG, without the XML prolog it is not a document of its own. */
    private function qrSvg(string $url): string
    {
        $svg = (new Builder(writer: new SvgWriter(), data: $url, size: 320, margin: 0))->build()->getString();

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    private function joinUrl(QuizLiveSession $session): string
    {
        return $this->generateUrl('app_quiz_live_entry', ['code' => $session->getRoomCode()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function managed(int $id, QuizLiveSessionRepository $repository): QuizLiveSession
    {
        $session = $repository->find($id);
        if (!$session instanceof QuizLiveSession || !$this->isGranted(QuizLiveSessionVoter::MANAGE, $session)) {
            throw $this->createNotFoundException();
        }

        return $session;
    }

    private function findTemplateOrNotFound(QuizTemplateRepository $repository, int $id): QuizTemplate
    {
        $template = $repository->find($id) ?? throw $this->createNotFoundException();

        if ($template->getTeacher() !== $this->host() && !$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_STAFF-LEAD')) {
            throw $this->createNotFoundException();
        }

        return $template;
    }

    /** A teacher or the administration: those who run contests. */
    private function host(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || [] === array_intersect(self::HOSTS, $user->getRoles())) {
            throw $this->createNotFoundException();
        }

        return $user;
    }
}
