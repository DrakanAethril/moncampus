<?php

declare(strict_types=1);

namespace App\Controller\QuizLive;

use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\QuizLiveSessionRepository;
use App\Security\FeatureAccess;
use App\Service\FixedAvatars;
use App\Service\JsonRequestPayload;
use App\Service\LiveGuestRefusedException;
use App\Service\LiveSessionStateException;
use App\Service\QuizLive\LiveGuestToken;
use App\Service\QuizLive\LiveNickname;
use App\Service\QuizLive\LiveResponse;
use App\Service\QuizLive\LiveScreenLabels;
use App\Service\QuizLive\LiveThemeCatalog;
use App\Service\QuizLiveSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * A live contest's public address, /live/{code} - what its QR code opens (design/validated/
 * quiz-live-public.md). `^/live/` is PUBLIC_ACCESS (config/packages/security.yaml): a signed-in
 * account and a visitor without one both arrive here.
 *
 * - An account enters at once, under its own name and avatar - it is never a guest -, a class
 *   contest taking its own students only.
 * - A visitor enters a public contest as a guest: a pseudonym, one of twenty avatars drawn among the
 *   ménagerie's hundred (App\Service\FixedAvatars), a token in a cookie (LiveGuestToken). A class contest sends it to sign in.
 * - Anybody may enter a contest under way.
 * - /live alone asks for the code: the waiting room shows « …/live » with the code below it, and
 *   that is what gets typed as often as the whole address.
 *
 * No #[RequiresFeature]: a visitor has no role to read the matrix with. The contest exists only when
 * the feature is lit for its host - and, for a class contest, for the account entering it, the rule
 * the class's own screens followed - else a 404 (tests/Functional/FeatureCoverageTest.php names this
 * controller).
 */
class PlayController extends AbstractController
{
    use TargetPathTrait;

    /**
     * The address without its code: a page that asks for it, and sends a code it finds on to the
     * contest's own address. A code nobody's contest carries is answered on the page itself - the
     * same answer as a contest whose host has the feature off, which does not exist either.
     */
    #[Route(path: '/live', name: 'app_quiz_live_join', methods: ['GET'])]
    public function join(Request $request, QuizLiveSessionRepository $sessions, FeatureAccess $features, LiveThemeCatalog $themes): Response
    {
        $typed = $request->query->has('code') ? trim($request->query->getString('code')) : null;
        $error = null;
        if (null !== $typed) {
            $code = self::codeOf($typed);
            $session = null !== $code ? $this->find($code, $sessions, $features) : null;
            if (null !== $session) {
                return $this->redirectToRoute('app_quiz_live_entry', ['code' => $session->getRoomCode()]);
            }
            $error = '' === $typed ? 'quizLiveJoinEmpty' : 'quizLiveJoinUnknown';
        }

        return $this->render('quiz_live/join.html.twig', [
            'theme' => $themes->default(),
            'typed' => $typed ?? '',
            'error' => $error,
        ], new Response('', 'quizLiveJoinUnknown' === $error ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
    }

    #[Route(path: '/live/{code}', name: 'app_quiz_live_entry', requirements: ['code' => '[A-Za-z0-9]{4,8}'], methods: ['GET'])]
    public function entry(string $code, Request $request, QuizLiveSessionRepository $sessions, QuizLiveSessionService $service, FeatureAccess $features, LiveThemeCatalog $themes): Response
    {
        $session = $this->session($code, $sessions, $features);
        $theme = $themes->resolve($session->getTheme());
        if ($session->isOver()) {
            return $this->render('quiz_live/closed.html.twig', ['session' => $session, 'theme' => $theme, 'reason' => 'over']);
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            if (null !== $service->participantFor($session, $user, null)) {
                return $this->redirectToRoute('app_quiz_live_play', ['code' => $session->getRoomCode()]);
            }
            if ($session->wasRemoved($user)) {
                return $this->render('quiz_live/closed.html.twig', ['session' => $session, 'theme' => $theme, 'reason' => 'removed']);
            }
            if (!$service->mayJoinAsAccount($session, $user) || !$features->isEnabled(Feature::QuizLive, $user)) {
                return $this->render('quiz_live/closed.html.twig', ['session' => $session, 'theme' => $theme, 'reason' => 'reserved'], new Response('', Response::HTTP_FORBIDDEN));
            }
            $service->joinAccount($session, $user);

            return $this->redirectToRoute('app_quiz_live_play', ['code' => $session->getRoomCode()]);
        }

        if (null !== $service->participantFor($session, null, LiveGuestToken::fromRequest($request, $session))) {
            return $this->redirectToRoute('app_quiz_live_play', ['code' => $session->getRoomCode()]);
        }

        // A visitor who signs in from here comes back here (LdapAuthenticator reads the target path).
        if ($request->hasSession()) {
            $this->saveTargetPath($request->getSession(), 'main', $request->getUri());
        }

        if (!$session->isPublic()) {
            return $this->render('quiz_live/closed.html.twig', ['session' => $session, 'theme' => $theme, 'reason' => 'sign-in']);
        }

        return $this->renderGuest($session, $themes, '', null, null);
    }

    /** A visitor's pseudonym and avatar - the guest page's one form. */
    #[Route(path: '/live/{code}/guest', name: 'app_quiz_live_guest', requirements: ['code' => '[A-Za-z0-9]{4,8}'], methods: ['POST'])]
    public function guest(
        string $code,
        Request $request,
        QuizLiveSessionRepository $sessions,
        QuizLiveSessionService $service,
        FeatureAccess $features,
        LiveThemeCatalog $themes,
        #[Autowire(service: 'limiter.quiz_live_guest')] RateLimiterFactory $limiter,
    ): Response {
        $session = $this->session($code, $sessions, $features);
        if ($this->getUser() instanceof User || !$session->isPublic() || $session->isOver()) {
            return $this->redirectToRoute('app_quiz_live_entry', ['code' => $session->getRoomCode()]);
        }

        if (!$this->isCsrfTokenValid('quiz_live_guest', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $nickname = LiveNickname::clean((string) $request->request->get('nickname', ''));
        $avatar = $request->request->getString('avatar');
        if (!FixedAvatars::exists($avatar)) {
            return $this->renderGuest($session, $themes, $nickname, null, 'quizLiveGuestAvatarRequired', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$limiter->create('ip:'.$request->getClientIp())->consume()->isAccepted()) {
            return $this->renderGuest($session, $themes, $nickname, $avatar, 'quizLiveGuestTooManyAttempts', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $joined = $service->joinGuest($session, $nickname, $avatar);
        } catch (LiveGuestRefusedException $exception) {
            return $this->renderGuest($session, $themes, $nickname, $avatar, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (LiveSessionStateException) {
            return $this->redirectToRoute('app_quiz_live_entry', ['code' => $session->getRoomCode()]);
        }

        $response = $this->redirectToRoute('app_quiz_live_play', ['code' => $session->getRoomCode()], Response::HTTP_SEE_OTHER);
        $response->headers->setCookie(LiveGuestToken::cookie($session, $joined['token'], $request->isSecure()));

        return $response;
    }

    #[Route(path: '/live/{code}/play', name: 'app_quiz_live_play', requirements: ['code' => '[A-Za-z0-9]{4,8}'], methods: ['GET'])]
    public function play(string $code, Request $request, QuizLiveSessionRepository $sessions, QuizLiveSessionService $service, FeatureAccess $features, LiveThemeCatalog $themes, LiveScreenLabels $labels, HubInterface $mercureHub): Response
    {
        $session = $this->session($code, $sessions, $features);
        $participant = $this->participant($session, $request, $service);
        if (null === $participant) {
            return $this->redirectToRoute('app_quiz_live_entry', ['code' => $session->getRoomCode()]);
        }

        $service->subscribe($request, $service->playersTopic($session));

        return $this->render('quiz_live/play.html.twig', [
            'session' => $session,
            'participant' => $participant,
            'theme' => $themes->resolve($session->getTheme()),
            'mercurePublicUrl' => $mercureHub->getPublicUrl(),
            'topic' => $service->playersTopic($session),
            'initialState' => $service->buildPlayerSnapshot($session, $participant),
            'context' => [
                'quizName' => (string) $session->getQuizInstance()?->getName(),
                'me' => [
                    'participantId' => $participant->getId(),
                    'name' => $participant->getDisplayName(),
                    'avatar' => $service->avatarFor($participant),
                    'guest' => $participant->isGuest(),
                ],
                'entryUrl' => $this->generateUrl('app_quiz_live_entry', ['code' => $session->getRoomCode()]),
                'homeUrl' => $participant->isGuest() ? null : $this->generateUrl('app_home'),
                'labels' => $labels->all(),
            ],
        ]);
    }

    /**
     * The phase as it is now, for this player - what the page asks again when it comes back from the
     * background or its stream reconnects: the hub replays nothing.
     */
    #[Route(path: '/live/{code}/state', name: 'app_quiz_live_play_state', requirements: ['code' => '[A-Za-z0-9]{4,8}'], methods: ['GET'])]
    public function state(string $code, Request $request, QuizLiveSessionRepository $sessions, QuizLiveSessionService $service, FeatureAccess $features): Response
    {
        $session = $this->session($code, $sessions, $features);
        $participant = $this->participant($session, $request, $service);

        return $this->json(['state' => $service->buildPlayerSnapshot($session, $participant), 'removed' => null === $participant]);
    }

    #[Route(path: '/live/{code}/answer', name: 'app_quiz_live_answer', requirements: ['code' => '[A-Za-z0-9]{4,8}'], methods: ['POST'])]
    public function answer(string $code, Request $request, QuizLiveSessionRepository $sessions, QuizLiveSessionService $service, FeatureAccess $features): Response
    {
        $session = $this->session($code, $sessions, $features);
        if (!$this->isCsrfTokenValid('quiz_live_answer', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $participant = $this->participant($session, $request, $service);
        if (null === $participant) {
            return $this->json(['error' => 'You have not joined this session.'], 409);
        }

        try {
            $service->submitAnswerFor($session, $participant, LiveResponse::fromPayload(JsonRequestPayload::fromRequest($request)));
        } catch (LiveSessionStateException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json(['ok' => true]);
    }

    /** The contest of this address - a 404 unless its host has the feature lit. */
    private function session(string $code, QuizLiveSessionRepository $sessions, FeatureAccess $features): QuizLiveSession
    {
        return $this->find($code, $sessions, $features) ?? throw $this->createNotFoundException();
    }

    /** The contest carrying this code, when its host has the feature lit. */
    private function find(string $code, QuizLiveSessionRepository $sessions, FeatureAccess $features): ?QuizLiveSession
    {
        $session = $sessions->findOneByCode($code);
        $host = $session?->getHost();

        return null !== $session && null !== $host && $features->isEnabled(Feature::QuizLive, $host) ? $session : null;
    }

    /**
     * The code in what was typed: the code itself, whatever its case and its spaces, or the whole
     * address pasted from somewhere. Null when nothing in it can be one.
     */
    private static function codeOf(string $typed): ?string
    {
        if (1 === preg_match('#/live/([A-Za-z0-9]{4,8})(?:[/?\#]|$)#', $typed, $match)) {
            return strtoupper($match[1]);
        }
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));

        return 1 === preg_match('/^[A-Z0-9]{4,8}$/', $code) ? $code : null;
    }

    private function participant(QuizLiveSession $session, Request $request, QuizLiveSessionService $service): ?QuizLiveParticipant
    {
        $user = $this->getUser();

        return $service->participantFor($session, $user instanceof User ? $user : null, LiveGuestToken::fromRequest($request, $session));
    }

    private function renderGuest(QuizLiveSession $session, LiveThemeCatalog $themes, string $nickname, ?string $avatar, ?string $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('quiz_live/guest.html.twig', [
            'session' => $session,
            'theme' => $themes->resolve($session->getTheme()),
            'nickname' => $nickname,
            'keptAvatar' => $avatar,
            'error' => $error,
            'nicknameMax' => LiveNickname::MAX,
        ], new Response('', $status));
    }
}
