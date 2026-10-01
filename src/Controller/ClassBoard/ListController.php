<?php

declare(strict_types=1);

namespace App\Controller\ClassBoard;

use App\Attribute\RequiresFeature;
use App\Entity\ClassBoard;
use App\Enum\Feature;
use App\Repository\ClassBoardRepository;
use App\Security\Voter\ClassBoardVoter;
use App\Service\ClassBoard\ClassBoardDuplicator;
use App\Service\ClassBoard\ClassBoardNaming;
use App\Service\ClassBoard\ClassBoardPrograms;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Tableau virtuel », the personal list of boards (design/validated/tableau-virtuel.md, §2):
 * create, rename, duplicate, delete. Every action is a plain form posted from the page and
 * answered by a redirect - there is nothing live about a list.
 *
 * A teacher's tool, like the rest of « Animer la classe »: whoever is neither teaching nor staff is
 * answered 404 even where class_tools has been lit for them - for them the screen does not exist,
 * it is not forbidden. A board only ever shows its owner what they could already read: its Classe
 * widgets ask StructureAccessChecker at every opening.
 */
#[RequiresFeature(Feature::ClassTools)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class ListController extends AbstractController
{
    use ClassBoardControllerTrait;

    private const string CSRF_TOKEN_ID = 'class_board_list';

    #[Route(path: '/tools/boards', name: 'app_class_board_index', methods: ['GET'])]
    public function index(Request $request, ClassBoardRepository $repository, ClassBoardPrograms $programs, ClassBoardNaming $naming): Response
    {
        $user = $this->currentUser();
        $boards = $repository->findForOwner($user);

        // « Toutes les classes », one class, or « Sans classe » - the filter only ever narrows.
        $filter = QueryValue::trimmed($request, 'class');
        if ('none' === $filter) {
            $boards = array_values(array_filter($boards, static fn (ClassBoard $board): bool => null === $board->getProgram()));
        } elseif (ctype_digit($filter)) {
            $boards = array_values(array_filter($boards, static fn (ClassBoard $board): bool => $board->getProgram()?->getId() === (int) $filter));
        }

        // The filter offers the classes the boards are actually linked to, plus the ones that may
        // be linked: a class one no longer teaches still has boards to find.
        $filterPrograms = [];
        foreach ([...$programs->linkable($user), ...array_filter(array_map(static fn (ClassBoard $board) => $board->getProgram(), $repository->findForOwner($user)))] as $program) {
            $filterPrograms[(int) $program->getId()] = $program;
        }

        return $this->render('class_board/index.html.twig', [
            'boards' => $boards,
            'filter' => $filter,
            'filterPrograms' => array_values($filterPrograms),
            'linkablePrograms' => $programs->linkable($user),
            'proposedName' => $naming->unique($user, $naming->proposed(new \DateTimeImmutable(), $request->getLocale())),
        ]);
    }

    #[Route(path: '/tools/boards', name: 'app_class_board_create', methods: ['POST'])]
    public function create(Request $request, ClassBoardPrograms $programs, ClassBoardNaming $naming, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request);
        $user = $this->currentUser();

        $name = trim($request->request->getString('name'));
        if ('' === $name) {
            $name = $naming->proposed(new \DateTimeImmutable(), $request->getLocale());
        }

        $program = null;
        if ('class' === $request->request->getString('link')) {
            $program = $programs->find($user, ctype_digit($request->request->getString('program')) ? (int) $request->request->getString('program') : null);
            if (null === $program) {
                $this->addFlash('error', 'classBoardNoClassChosenFlashMessage');

                return $this->redirectToRoute('app_class_board_index');
            }
        }

        $board = new ClassBoard($user, $naming->unique($user, mb_substr($name, 0, 255)), $program);
        $entityManager->persist($board);
        $entityManager->flush();

        return $this->redirectToRoute('app_class_board_show', ['id' => $board->getId()]);
    }

    // Name and class together, from the same in-page panel. A rename to a name already taken is
    // refused rather than numbered: renaming is a correction, and two rows by the same name would
    // be the very confusion the rule exists to prevent. Changing the class deletes no widget -
    // those that pointed at the old class's objects say « Source introuvable » at the next opening.
    #[Route(path: '/tools/boards/{id}/rename', name: 'app_class_board_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rename(int $id, Request $request, ClassBoardRepository $repository, ClassBoardPrograms $programs, ClassBoardNaming $naming, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request);
        $board = $this->findBoard($id, $repository, ClassBoardVoter::EDIT);
        $user = $this->currentUser();

        $name = mb_substr(trim($request->request->getString('name')), 0, 255);
        if ('' === $name) {
            $this->addFlash('error', 'classBoardEmptyNameFlashMessage');

            return $this->redirectToRoute('app_class_board_index');
        }
        if ($naming->isTaken($user, $name, $board->getId())) {
            $this->addFlash('error', 'classBoardDuplicateNameFlashMessage');

            return $this->redirectToRoute('app_class_board_index');
        }

        $programField = $request->request->getString('program');
        if ('' === $programField) {
            $board->setProgram(null);
        } elseif (ctype_digit($programField) && (int) $programField !== $board->getProgram()?->getId()) {
            $program = $programs->find($user, (int) $programField);
            if (null === $program) {
                throw $this->createNotFoundException();
            }
            $board->setProgram($program);
        }

        $board->setName($name)->markModified();
        $entityManager->flush();

        return $this->redirectToRoute('app_class_board_index');
    }

    #[Route(path: '/tools/boards/{id}/duplicate', name: 'app_class_board_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request, ClassBoardRepository $repository, ClassBoardDuplicator $duplicator): Response
    {
        $this->assertCsrf($request);
        $duplicator->duplicate($this->findBoard($id, $repository));

        return $this->redirectToRoute('app_class_board_index');
    }

    #[Route(path: '/tools/boards/{id}/delete', name: 'app_class_board_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, ClassBoardRepository $repository, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request);
        $board = $this->findBoard($id, $repository, ClassBoardVoter::DELETE);
        $entityManager->remove($board);
        $entityManager->flush();

        $this->addFlash('success', 'classBoardDeletedFlashMessage');

        return $this->redirectToRoute('app_class_board_index');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
