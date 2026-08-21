<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ConsoleSession;
use App\Entity\User;
use App\Repository\ConsoleSessionRepository;
use App\Repository\GuestAccountRepository;
use App\Security\Voter\GuestConsoleVoter;
use App\Service\Console\ConsoleAddressUnknownException;
use App\Service\Console\ConsoleLimitReachedException;
use App\Service\Console\ConsoleNotReadyException;
use App\Service\Console\ConsoleScreen;
use App\Service\Console\ConsoleSessionOpener;
use App\Service\Console\ConsoleUnavailableException;
use App\Service\Console\GuestPty;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\JsonRequestPayload;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The machine console: a real terminal on a machine MonCampus installed, opened from the platform.
 *
 * **Nothing is held here.** Every exchange is an ordinary POST that opens SSH, pushes the bytes
 * somebody typed into the machine's own `tmux`, photographs the screen and hangs up. There is no
 * daemon, no WebSocket, no second copy of any secret and no line to add to the deployment script -
 * the pseudo-terminal lives inside the machine, which is the whole design
 * (design/validated/guest-console.md, §2).
 *
 * **Two doors, and not a third.** A teacher arrives from the card on « Mes machines virtuelles »,
 * through the account they hold on the machine, and App\Security\Voter\GuestConsoleVoter answers:
 * the account is theirs *and* they teach the formation of its batch. An administrator arrives from
 * /infrastructure, which access_control guards on ROLE_ADMIN, and never touches this voter. Neither
 * rule widens the other, and a student passes neither: they already have a shell on that machine -
 * their own account, with the password they set themselves - and a console opens on `moncampus`,
 * which has `sudo`.
 *
 * The screen route deliberately does **not** touch the machine: it draws the frame, and the first
 * exchange is what opens the session. That is what makes « préparation de la console… » a state
 * the screen can show rather than a wait it holds.
 */
#[IsGranted('ROLE_USER')]
class ConsoleController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * The teacher's door, and the screen.
     *
     * `{id}` is a GuestAccount: the console is reached *through the account*, which is what makes
     * the perimeter calculated rather than declared - no new field, no checkbox, and a machine the
     * platform did not install has no account and therefore no door.
     */
    #[Route(path: '/console/{id}', name: 'app_console', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(
        GuestAccountRepository $accounts,
        ConsoleSessionOpener $opener,
        ConsoleScreen $screen,
        int $id,
    ): Response {
        $account = $accounts->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(GuestConsoleVoter::CONSOLE, $account);

        try {
            $session = $opener->openForAccount($account, $this->currentUser());
        } catch (ConsoleLimitReachedException|ConsoleAddressUnknownException $exception) {
            return $this->renderRefusal($screen, $exception);
        }

        return $this->render('console/index.html.twig', $screen->forTeacher($session, $account));
    }

    /**
     * One exchange: what was typed goes in, the screen comes back.
     *
     * **The only hot path**, and the only one that holds a worker: it waits inside the machine for
     * up to eight seconds and answers the moment the screen changes. A keystroke during that wait
     * aborts the request in flight from the browser and starts another.
     */
    #[Route(path: '/console/sessions/{id}/exchange', name: 'app_console_exchange', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function exchange(
        Request $request,
        ConsoleSessionRepository $sessions,
        GuestShellFactory $shellFactory,
        GuestPty $pty,
        ConsoleScreen $screen,
        EntityManagerInterface $entityManager,
        int $id,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);
        $payload = JsonRequestPayload::fromRequest($request);

        try {
            // The short ceiling is the console's, and the reason GuestShellFactory::open() takes
            // one at all: an exchange must hand the worker back in ten seconds, not in five
            // minutes nobody is waiting through.
            $shell = $shellFactory->open($session->getIp(), timeoutSeconds: GuestPty::COMMAND_TIMEOUT_SECONDS);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException $exception) {
            return $this->json($screen->unreachable($session, $exception));
        }

        try {
            $pane = $pty->exchange(
                $shell,
                $payload->string('keys'),
                $payload->string('since'),
                $payload->int('columns'),
                $payload->int('rows'),
            );
        } catch (ConsoleUnavailableException $exception) {
            return $this->json(['ok' => false, 'state' => 'noConsole', 'message' => $this->translator->trans($exception->getMessage())]);
        } catch (ConsoleNotReadyException) {
            return $this->json(['ok' => false, 'state' => 'preparing', 'message' => $this->translator->trans('consolePreparingMessage')]);
        } catch (GuestUnreachableException $exception) {
            return $this->json($screen->unreachable($session, $exception));
        } catch (\InvalidArgumentException) {
            // Not a keystroke. Nothing legitimate reaches here, so it says nothing useful back.
            throw $this->createNotFoundException();
        } finally {
            $shell->disconnect();
        }

        $session->touch();
        $entityManager->flush();

        return $this->json($screen->pane($session, $pane));
    }

    /**
     * Ends the trace when the tab goes away - a `sendBeacon`, which is why it answers no content.
     *
     * Nothing is killed inside the machine, deliberately: that is the entire point of the design.
     * The `apt upgrade` started before lunch is still running when the tab is opened again.
     */
    #[Route(path: '/console/sessions/{id}/close', name: 'app_console_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(
        Request $request,
        ConsoleSessionRepository $sessions,
        ConsoleSessionOpener $opener,
        int $id,
    ): Response {
        // `sendBeacon` carries no header, so the token travels in the query here and only here.
        // It closes a row that already belongs to the person connected: the worst a replayed URL
        // achieves is ending a trace of their own, and nothing at all inside the machine.
        if (!$this->isCsrfTokenValid('console_exchange', QueryValue::trimmed($request, 'token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $opener->close($this->ownSessionUnchecked($sessions, $id));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The session this request is about, or a refusal.
     *
     * « It is yours » and nothing else. Both doors write the same row and the person who opened it
     * is the person who may type into it - a rule that needs no role and therefore widens for
     * nobody. The door that let them open it was judged once, at opening.
     */
    private function ownSession(Request $request, ConsoleSessionRepository $sessions, int $id): ConsoleSession
    {
        if (!$this->isCsrfTokenValid('console_exchange', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->ownSessionUnchecked($sessions, $id);
    }

    /** The ownership half alone, for the one caller whose token does not travel in a header. */
    private function ownSessionUnchecked(ConsoleSessionRepository $sessions, int $id): ConsoleSession
    {
        $session = $sessions->find($id) ?? throw $this->createNotFoundException();
        $openerId = $session->getOpenedBy()?->getId();

        if (null === $openerId || $openerId !== $this->currentUser()->getId() || !$session->isOpen()) {
            throw $this->createAccessDeniedException();
        }

        return $session;
    }

    private function renderRefusal(ConsoleScreen $screen, \RuntimeException $exception): Response
    {
        return $this->render('console/refused.html.twig', $screen->refusal($exception));
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
