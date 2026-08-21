<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ConsoleSession;
use App\Entity\ConsoleSnippet;
use App\Entity\User;
use App\Repository\ConsoleSessionRepository;
use App\Repository\FileLibraryNodeRepository;
use App\Repository\GuestAccountRepository;
use App\Security\Voter\GuestConsoleVoter;
use App\Service\Console\ConsoleAddressUnknownException;
use App\Service\Console\ConsoleBroadcaster;
use App\Service\Console\ConsoleBroadcastRefusedException;
use App\Service\Console\ConsoleFileRefusedException;
use App\Service\Console\ConsoleFileTooLargeException;
use App\Service\Console\ConsoleHarvest;
use App\Service\Console\ConsoleIdentity;
use App\Service\Console\ConsoleLimitReachedException;
use App\Service\Console\ConsoleNotReadyException;
use App\Service\Console\ConsolePalette;
use App\Service\Console\ConsoleScreen;
use App\Service\Console\ConsoleSessionOpener;
use App\Service\Console\ConsoleTranscript;
use App\Service\Console\ConsoleUnavailableException;
use App\Service\Console\GuestFileDrop;
use App\Service\Console\GuestPty;
use App\Service\FileUploadService;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyUnavailableException;
use App\Service\JsonRequestPayload;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        ConsoleTranscript $transcript,
        EntityManagerInterface $entityManager,
        int $id,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);
        $payload = JsonRequestPayload::fromRequest($request);

        // **The session lock is released here, and this is not an optimisation.** PHP holds an
        // exclusive lock on the session file for the whole of a request, and this one deliberately
        // lasts up to eight seconds. A keystroke during that wait aborts the request in the
        // browser - but aborting a fetch does not stop the server, so the abandoned request keeps
        // the lock to the end of its own budget and the keystroke that replaced it *queues behind
        // it*. Measured before this line: a keystroke echoed in 7.2 s at the median instead of
        // 300 ms, with the two numbers alternating exactly as the lock was free or held. Nothing
        // below writes to the session, so closing it costs nothing.
        $request->getSession()->save();

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

        // The panel, never the keystrokes. The screen that just came back is folded into what the
        // session leaves behind; what is still on screen stays rewritable until it scrolls off.
        $recorded = $transcript->record(
            $session->getTranscript() ?? '',
            $session->getTranscriptStableLength(),
            $pane,
            $session->isTranscriptTruncated(),
        );
        $session->recordTranscript($recorded->text, $recorded->stableLength, $recorded->truncated);

        $entityManager->flush();

        return $this->json($screen->pane($session, $pane));
    }

    /**
     * `Ctrl+K`: the command palette, its three sources merged and labelled.
     *
     * A GET answering JSON, and no session write: the palette is a reading, and it is re-read at
     * every keystroke in its field.
     */
    #[Route(path: '/console/sessions/{id}/palette', name: 'app_console_palette', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function palette(
        Request $request,
        ConsoleSessionRepository $sessions,
        ConsolePalette $palette,
        int $id,
    ): JsonResponse {
        $session = $this->ownSessionUnchecked($sessions, $id);

        return $this->json($palette->build($this->currentUser(), $session, QueryValue::trimmed($request, 'q')));
    }

    /**
     * « Enregistrer comme extrait » - the last command that went past, kept.
     *
     * This is how a personal library fills up: from the console, in one gesture, at the moment the
     * command proved useful. A form nobody opens is a library that stays empty.
     */
    #[Route(path: '/console/sessions/{id}/snippet', name: 'app_console_snippet_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addSnippet(
        Request $request,
        ConsoleSessionRepository $sessions,
        EntityManagerInterface $entityManager,
        int $id,
    ): JsonResponse {
        $this->ownSession($request, $sessions, $id);
        $payload = JsonRequestPayload::fromRequest($request);
        $command = trim($payload->string('command'));
        $label = trim($payload->string('label'));

        if ('' === $command) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('consoleSnippetEmptyMessage')]);
        }

        $user = $this->currentUser();
        $snippet = new ConsoleSnippet($user, '' === $label ? mb_substr($command, 0, 120) : mb_substr($label, 0, 120), $command);
        $snippet->setCreatedBy($user);
        $entityManager->persist($snippet);
        $entityManager->flush();

        return $this->json(['ok' => true, 'message' => $this->translator->trans('consoleSnippetSavedMessage')]);
    }

    /**
     * « Devenir » one of the machine's accounts.
     *
     * `sudo -iu <login>`: their `$HOME`, their rights, their `.bashrc` - so their problem gets
     * *reproduced* rather than imagined. The login is checked against the accounts declared on the
     * machine and never taken as given: this route types into a root-capable shell.
     */
    #[Route(path: '/console/sessions/{id}/become/{login}', name: 'app_console_become', requirements: ['id' => '\\d+', 'login' => '[a-z0-9_.-]{1,32}'], methods: ['POST'])]
    public function become(
        Request $request,
        ConsoleSessionRepository $sessions,
        GuestAccountRepository $accounts,
        GuestShellFactory $shellFactory,
        GuestPty $pty,
        EntityManagerInterface $entityManager,
        int $id,
        string $login,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);
        $host = $session->getHost();

        // The declared accounts of *this* machine, and nothing else. A login that is not one of
        // them is not a typo to be forgiven - it is somebody trying a name.
        $known = null !== $host
            ? $accounts->findOneForMachine($host, $session->getNode(), $session->getVmid(), $login)
            : null;

        if (null === $known && ConsoleIdentity::PLATFORM_ACCOUNT !== $login) {
            throw $this->createNotFoundException();
        }

        try {
            $shell = $shellFactory->open($session->getIp(), timeoutSeconds: GuestPty::COMMAND_TIMEOUT_SECONDS);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('consoleMachineBootingMessage')]);
        }

        try {
            // Back to the platform account is an `exit`, not a second `sudo`: stacking a login shell
            // on a login shell is how somebody ends up three deep without knowing it.
            $pty->sendLine($shell, ConsoleIdentity::PLATFORM_ACCOUNT === $login ? 'exit' : \sprintf('sudo -iu %s', $login));
        } finally {
            $shell->disconnect();
        }

        $session->setUnixUser($login);
        $entityManager->flush();

        return $this->json(['ok' => true, 'unixUser' => $login]);
    }

    /**
     * A file onto the machine: from the reader's computer, or from their file library.
     *
     * Both land in the console's current directory, through `base64` on the session that is already
     * open - no port, no service, no second authentication path.
     */
    #[Route(path: '/console/sessions/{id}/file', name: 'app_console_file_send', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function sendFile(
        Request $request,
        ConsoleSessionRepository $sessions,
        FileLibraryNodeRepository $nodes,
        FileUploadService $uploads,
        GuestShellFactory $shellFactory,
        GuestFileDrop $drop,
        int $id,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);
        $uploaded = $request->files->get('file');
        $nodeId = QueryValue::nullableInt($request, 'node') ?? (int) $request->request->get('node');

        if ($uploaded instanceof UploadedFile) {
            $name = $uploaded->getClientOriginalName();
            $contents = (string) file_get_contents($uploaded->getPathname());
        } else {
            $node = $nodes->find($nodeId);
            $key = $node?->getStorageKey();

            // Their own library only. The library is owner-scoped, and a console is not a way
            // around that.
            if (null === $node || null === $key || $node->getOwner()->getId() !== $this->currentUser()->getId()) {
                throw $this->createNotFoundException();
            }

            $name = $node->getOriginalName() ?? $node->getName();
            $contents = $uploads->read($key);
        }

        try {
            $shell = $shellFactory->open($session->getIp(), timeoutSeconds: GuestPty::INSTALL_TIMEOUT_SECONDS);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('consoleMachineBootingMessage')]);
        }

        try {
            $path = $drop->send($shell, $name, $contents);
        } catch (ConsoleFileTooLargeException|ConsoleFileRefusedException $exception) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans($exception->getMessage())]);
        } finally {
            $shell->disconnect();
        }

        return $this->json([
            'ok' => true,
            'message' => $this->translator->trans('consoleFileSentMessage', ['%name%' => GuestFileDrop::safeName($name), '%path%' => $path]),
        ]);
    }

    /**
     * A file off the machine, into the reader's file library.
     *
     * The way a piece of work gets picked up off a machine without a USB stick. It lands in the
     * reader's own library - the library is owner-scoped, and this feature does not invent a second
     * ownership rule - inside a folder named after the batch, so a class's harvest stays together.
     */
    #[Route(path: '/console/sessions/{id}/fetch', name: 'app_console_file_fetch', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function fetchFile(
        Request $request,
        ConsoleSessionRepository $sessions,
        GuestShellFactory $shellFactory,
        GuestFileDrop $drop,
        ConsoleHarvest $harvest,
        int $id,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);
        $path = trim(JsonRequestPayload::fromRequest($request)->string('path'));

        if ('' === $path || !str_starts_with($path, '/')) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('consoleFetchPathMessage')]);
        }

        try {
            $shell = $shellFactory->open($session->getIp(), timeoutSeconds: GuestPty::INSTALL_TIMEOUT_SECONDS);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('consoleMachineBootingMessage')]);
        }

        try {
            $contents = $drop->fetch($shell, $path);
        } catch (ConsoleFileTooLargeException|ConsoleFileRefusedException $exception) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans($exception->getMessage())]);
        } finally {
            $shell->disconnect();
        }

        $node = $harvest->store($this->currentUser(), $session, basename($path), $contents);

        return $this->json([
            'ok' => true,
            'message' => $this->translator->trans('consoleFileFetchedMessage', ['%name%' => $node->getName(), '%folder%' => $node->getParent()?->getName() ?? '']),
        ]);
    }

    /**
     * `Ctrl+F`: searching what has already scrolled past, in the machine's own scrollback.
     *
     * Read from tmux rather than from the transcript: the scrollback holds three thousand lines of
     * *this* session as the machine has them, which is more and fresher than what has been folded
     * into a record.
     */
    #[Route(path: '/console/sessions/{id}/search', name: 'app_console_search', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function search(
        Request $request,
        ConsoleSessionRepository $sessions,
        GuestShellFactory $shellFactory,
        GuestPty $pty,
        int $id,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);
        $needle = trim(JsonRequestPayload::fromRequest($request)->string('q'));

        if ('' === $needle) {
            return $this->json(['ok' => true, 'matches' => []]);
        }

        try {
            $shell = $shellFactory->open($session->getIp(), timeoutSeconds: GuestPty::COMMAND_TIMEOUT_SECONDS);
        } catch (GuestUnreachableException|PlatformKeyUnavailableException) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('consoleMachineBootingMessage')]);
        }

        try {
            $history = $pty->history($shell, 3000);
        } finally {
            $shell->disconnect();
        }

        $matches = [];

        foreach (explode("\n", $history) as $number => $line) {
            if (str_contains(mb_strtolower($line), mb_strtolower($needle))) {
                $matches[] = ['line' => $number + 1, 'text' => mb_substr(rtrim($line), 0, 300)];
            }
        }

        // Newest first: what somebody is looking for in three thousand lines is almost always what
        // has just gone past.
        return $this->json(['ok' => true, 'matches' => \array_slice(array_reverse($matches), 0, 60)]);
    }

    /**
     * Arming and disarming the broadcast.
     *
     * Armed explicitly and never by default: the frame turns copper, the status bar names the batch
     * and the number of machines, and nothing goes out without a confirmation that names that
     * number. It lets go by itself after ten minutes without a send - a console found still armed
     * the next morning is a trap.
     */
    #[Route(path: '/console/sessions/{id}/arm', name: 'app_console_arm', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function arm(
        Request $request,
        ConsoleSessionRepository $sessions,
        ConsoleBroadcaster $broadcaster,
        EntityManagerInterface $entityManager,
        int $id,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);

        if ($session->isBroadcastArmed()) {
            $session->disarmBroadcast();
            $entityManager->flush();

            return $this->json(['ok' => true, 'armed' => false]);
        }

        try {
            $machines = $broadcaster->machinesOf($session);
        } catch (ConsoleBroadcastRefusedException $exception) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans($exception->getMessage())]);
        }

        $session->armBroadcast();
        $entityManager->flush();

        return $this->json([
            'ok' => true,
            'armed' => true,
            'machines' => \count($machines),
            'batch' => $broadcaster->batchOf($session)->getLabel(),
            'minutesLeft' => $session->broadcastMinutesLeft(),
        ]);
    }

    /**
     * One line, every machine of the batch.
     *
     * The function that justifies the screen, and the one that breaks the fastest - hence the arming
     * above, the confirmation that names the number, and this row in the journal.
     */
    #[Route(path: '/console/sessions/{id}/broadcast', name: 'app_console_broadcast', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function broadcast(
        Request $request,
        ConsoleSessionRepository $sessions,
        ConsoleBroadcaster $broadcaster,
        int $id,
    ): JsonResponse {
        $session = $this->ownSession($request, $sessions, $id);

        // Armed, and still armed: ten minutes without a send and the console has let go. Checked
        // here and not only in the browser - a stale tab is exactly the case this guards.
        if (!$session->isBroadcastArmed()) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans('consoleBroadcastNotArmedMessage')]);
        }

        $request->getSession()->save();

        try {
            $sent = $broadcaster->send($session, JsonRequestPayload::fromRequest($request)->string('command'), $this->currentUser());
        } catch (ConsoleBroadcastRefusedException $exception) {
            return $this->json(['ok' => false, 'message' => $this->translator->trans($exception->getMessage())]);
        }

        return $this->json([
            'ok' => true,
            'command' => $sent->getCommand(),
            'done' => $sent->countDone(),
            'refused' => $sent->countRefused(),
            // Composé ici et non dans le navigateur : « 1 injoignables » est ce qu'on obtient en
            // recollant des morceaux côté JS, et l'accord se décide dans les traductions.
            'summary' => \sprintf(
                '%s · %s',
                $this->translator->trans('consoleBroadcastDoneCount', ['%count%' => $sent->countDone()]),
                $this->translator->trans('consoleBroadcastRefusedCount', ['%count%' => $sent->countRefused()]),
            ),
            'results' => $sent->getResults(),
            'minutesLeft' => $session->broadcastMinutesLeft(),
        ]);
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
