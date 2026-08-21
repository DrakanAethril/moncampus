<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ConsoleSnippet;
use App\Entity\User;
use App\Repository\ConsoleSnippetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mes extraits de commande » - the personal half of the command palette.
 *
 * A screen for tidying rather than for filling: a library fills up from the console, one gesture at
 * the moment a command proved useful (App\Controller\ConsoleController::addSnippet). What is done
 * *here* is renaming, reordering, sharing and deleting - the things one does occasionally and
 * deliberately.
 *
 * Open to whoever may hold a console, which is teachers and administrators. Not narrowed further by
 * a Voter: a snippet is a line of text somebody wrote for themselves, and there is nothing here for
 * a rule to protect that a plain ownership check does not already cover.
 */
// Teachers and administrators, and deliberately not ROLE_STAFF: this screen belongs to the console,
// and the console's two doors are those two. Nothing about a snippet needs a further rule - a shared
// one is readable by colleagues and writable by nobody but its author, which is an ownership check.
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN")'))]
class ConsoleSnippetController extends AbstractController
{
    #[Route(path: '/console/snippets', name: 'app_console_snippets', methods: ['GET'])]
    public function index(ConsoleSnippetRepository $snippets): Response
    {
        return $this->render('console/snippets.html.twig', [
            'snippets' => $snippets->findOwnedBy($this->currentUser()),
            'shared' => array_values(array_filter(
                $snippets->findVisibleTo($this->currentUser()),
                fn (ConsoleSnippet $snippet): bool => $snippet->getOwner()?->getId() !== $this->currentUser()->getId(),
            )),
        ]);
    }

    #[Route(path: '/console/snippets/new', name: 'app_console_snippets_new', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->assertToken($request);

        $label = trim((string) $request->request->get('label'));
        $command = trim((string) $request->request->get('command'));

        if ('' === $command) {
            $this->addFlash('error', 'consoleSnippetEmptyMessage');

            return $this->redirectToRoute('app_console_snippets');
        }

        $user = $this->currentUser();
        $snippet = new ConsoleSnippet($user, '' === $label ? mb_substr($command, 0, 120) : mb_substr($label, 0, 120), $command);
        $snippet->setShared($request->request->getBoolean('shared'));
        $snippet->setCreatedBy($user);

        $entityManager->persist($snippet);
        $entityManager->flush();

        $this->addFlash('success', 'consoleSnippetSavedMessage');

        return $this->redirectToRoute('app_console_snippets');
    }

    #[Route(path: '/console/snippets/{id}', name: 'app_console_snippets_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(Request $request, ConsoleSnippetRepository $snippets, EntityManagerInterface $entityManager, int $id): Response
    {
        $snippet = $this->ownSnippet($request, $snippets, $id);

        if (null !== $request->request->get('delete')) {
            $entityManager->remove($snippet);
            $entityManager->flush();
            $this->addFlash('success', 'consoleSnippetDeletedMessage');

            return $this->redirectToRoute('app_console_snippets');
        }

        $label = trim((string) $request->request->get('label'));
        $command = trim((string) $request->request->get('command'));

        if ('' !== $label) {
            $snippet->setLabel(mb_substr($label, 0, 120));
        }

        if ('' !== $command) {
            $snippet->setCommand($command);
        }

        $snippet->setShared($request->request->getBoolean('shared'));
        $snippet->setLastUpdatedBy($this->currentUser());
        $entityManager->flush();

        $this->addFlash('success', 'consoleSnippetSavedMessage');

        return $this->redirectToRoute('app_console_snippets');
    }

    private function ownSnippet(Request $request, ConsoleSnippetRepository $snippets, int $id): ConsoleSnippet
    {
        $this->assertToken($request);

        $snippet = $snippets->find($id) ?? throw $this->createNotFoundException();

        // Ownership, plainly: a shared snippet is readable by colleagues and writable by nobody but
        // the person who wrote it.
        if ($snippet->getOwner()?->getId() !== $this->currentUser()->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $snippet;
    }

    private function assertToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('console_snippets', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
