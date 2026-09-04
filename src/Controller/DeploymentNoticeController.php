<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\DeploymentOutcome;
use App\Service\DeploymentNoticeBoard;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two ends of the « une mise à jour est en cours » banner: what the deploy workflow calls to
 * raise and lower it, and what every open tab asks to find out.
 *
 * **Why HTTP and not a console command over the deploy's own SSH session.** The banner has to go up
 * where Discord says the deploy started - before the VPN is even dialled - and come down where
 * Discord announces the outcome, which is after the VPN has been dropped and the site has been
 * polled from the open internet. There is no SSH session open at either of those two instants, and
 * the application's public address is the one thing the workflow demonstrably has at both.
 *
 * **What that opens, and what it does not.** One route accepts a write, it flips a boolean, and it
 * is refused unless the caller presents the shared secret - compared with hash_equals, so the
 * comparison itself leaks nothing. With no secret configured the route answers 404: an unset token
 * must never mean an open door, which is the failure mode that would matter. The worst a leaked
 * token buys is a banner announcing a restart that is not coming, which expires on its own
 * (App\Service\DeploymentNoticeBoard::DEFAULT_WINDOW_MINUTES).
 *
 * The read route is deliberately public and deliberately anonymous: it is what the login screen
 * polls, and somebody who is not logged in during a deploy is precisely the person who needs to be
 * told. It says nothing a visitor cannot already see on the page.
 */
class DeploymentNoticeController extends AbstractController
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire(env: 'DEPLOYMENT_NOTICE_TOKEN')] private readonly string $token,
        private readonly DeploymentNoticeBoard $board,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
    ) {
    }

    /**
     * What an open tab asks every minute. Returns the banner already rendered rather than the
     * fields to build it from - one template, one truth about what it says, and the poll cannot
     * drift from the server-rendered version sitting on the page next to it.
     */
    #[Route(path: '/deployment/notice', name: 'app_deployment_notice_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $this->speak(QueryValue::trimmed($request, 'locale'));

        $notice = $this->board->current();

        $response = new JsonResponse([
            'deploying' => null !== $notice,
            'html' => null === $notice ? '' : $this->renderView('_deployment_notice.html.twig', ['notice' => $notice]),
        ]);

        // Whatever sits between the browser and the app, this answer is never worth a second.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * What the workflow calls. `phase=start` raises the notice, `phase=success` / `phase=failure`
     * lower it - the same three phases the Discord announcement already speaks, so the two cannot
     * describe different deploys.
     */
    #[Route(path: '/deployment/notice', name: 'app_deployment_notice_announce', methods: ['POST'])]
    public function announce(Request $request): JsonResponse
    {
        if (!$this->isAuthorised($request)) {
            // 404 and not 403: an endpoint that answers "wrong token" is an endpoint that confirms
            // it is there and takes tokens.
            throw $this->createNotFoundException();
        }

        $phase = (string) $request->request->get('phase', $request->query->get('phase', ''));

        return match ($phase) {
            'start' => $this->raise($request),
            'success' => $this->lower(DeploymentOutcome::Succeeded),
            'failure' => $this->lower(DeploymentOutcome::Failed),
            default => new JsonResponse(['error' => 'unknown phase'], Response::HTTP_BAD_REQUEST),
        };
    }

    private function raise(Request $request): JsonResponse
    {
        $version = trim((string) $request->request->get('version', $request->query->get('version', '')));
        $notice = $this->board->open('' === $version ? null : mb_substr($version, 0, 32));

        return new JsonResponse([
            'deploying' => true,
            'version' => $notice->getVersion(),
            'expiresAt' => $notice->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function lower(DeploymentOutcome $outcome): JsonResponse
    {
        // « nothing was open » is not an error: a run replayed after its notice expired, or an end
        // announced twice, both mean the same thing to the workflow - there is no banner up.
        return new JsonResponse(['deploying' => false, 'closed' => $this->board->close($outcome)]);
    }

    /**
     * The language to answer in, named by the caller rather than read off the session.
     *
     * The poll deliberately sends no cookie (assets/controllers/deployment_notice_controller.js):
     * that is what keeps a banner check every 60s per tab from opening - and locking, and endlessly
     * postponing the expiry of - the reader's session. It costs the one thing the cookie carried
     * that this answer needs, so the page states it instead.
     *
     * Checked against the configured locales rather than trusted: this is a public route, and a
     * locale is passed straight to the translator. Anything else leaves the default in place, which
     * is the same French the fallback would have produced anyway.
     */
    private function speak(string $locale): void
    {
        if ('' === $locale || !\in_array($locale, $this->enabledLocales, true)) {
            return;
        }

        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }

    private function isAuthorised(Request $request): bool
    {
        if ('' === $this->token) {
            return false;
        }

        $header = (string) $request->headers->get('Authorization', '');
        $presented = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';

        return '' !== $presented && hash_equals($this->token, $presented);
    }
}
