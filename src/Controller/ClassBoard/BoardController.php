<?php

declare(strict_types=1);

namespace App\Controller\ClassBoard;

use App\Attribute\RequiresFeature;
use App\Enum\ClassBoardBackground;
use App\Enum\Feature;
use App\Repository\ClassBoardRepository;
use App\Security\Voter\ClassBoardVoter;
use App\Service\ClassBoard\ClassBoardDrawings;
use App\Service\ClassBoard\ClassBoardLayout;
use App\Service\ClassBoard\ClassBoardNaming;
use App\Service\ClassBoard\ClassBoardView;
use App\Service\ClassBoard\ClassBoardWidgetData;
use App\Service\ClassBoard\InvalidClassBoardLayoutException;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The board itself - the projected screen - and its automatic save
 * (design/validated/tableau-virtuel.md, §4 and §8).
 */
#[RequiresFeature(Feature::ClassTools)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class BoardController extends AbstractController
{
    use ClassBoardControllerTrait;

    public const string CSRF_TOKEN_ID = 'class_board';

    #[Route(path: '/tools/boards/{id}', name: 'app_class_board_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request, ClassBoardRepository $repository, ClassBoardView $view, ClassBoardWidgetData $widgetData, Authorization $mercureAuthorization): Response
    {
        $board = $this->findBoard($id, $repository);
        $widgets = $view->widgets($board);

        $topics = $view->mercureTopics($widgets);
        if ([] !== $topics) {
            $mercureAuthorization->setCookie($request, $topics, [], [], 'subscriber');
        }

        return $this->render('class_board/show.html.twig', [
            'board' => $board,
            'widgets' => $widgets,
            'dock' => $view->dock($board, $this->currentUser()),
            'classState' => $widgetData->classState($board),
            'backgrounds' => ClassBoardBackground::cases(),
        ]);
    }

    // The automatic save: the whole document, every time, 2 s after the last change. A save that
    // started from an older revision than the stored one is refused (409) - the same board open in
    // another tab has written since, and overwriting it would erase that tab's work in silence.
    #[Route(path: '/tools/boards/{id}/layout', name: 'app_class_board_save', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function save(int $id, Request $request, ClassBoardRepository $repository, ClassBoardLayout $layout, ClassBoardDrawings $drawings, EntityManagerInterface $entityManager, LoggerInterface $logger): JsonResponse
    {
        $this->assertCsrf($request);
        $board = $this->findBoard($id, $repository, ClassBoardVoter::EDIT);

        $content = $request->getContent();
        $payload = JsonRequestPayload::fromJson($content);
        if ($payload->int('revision') !== $board->getRevision()) {
            return $this->json(['error' => 'stale_revision', 'revision' => $board->getRevision()], Response::HTTP_CONFLICT);
        }

        $background = ClassBoardBackground::tryFrom($payload->string('background'));
        if (null === $background) {
            return $this->json(['error' => 'invalid_layout'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $widgets = $layout->normalizeJson($content, $board->getLayout(), $board->getId());
        } catch (InvalidClassBoardLayoutException $exception) {
            $logger->warning('Class board layout refused: {reason}', ['reason' => $exception->getMessage(), 'board' => $board->getId()]);

            return $this->json(['error' => 'invalid_layout'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $drawings->forgetRemoved($board->getLayout(), $widgets);
        $board->setLayout($widgets)->setBackground($background)->touch();
        $entityManager->flush();

        return $this->json([
            'revision' => $board->getRevision(),
            'savedAt' => $board->getUpdatedAt()->format('H:i'),
        ]);
    }

    // The name in the top bar. The rule of the list: a name already taken is refused, never
    // numbered behind the teacher's back.
    #[Route(path: '/tools/boards/{id}/name', name: 'app_class_board_name', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rename(int $id, Request $request, ClassBoardRepository $repository, ClassBoardNaming $naming, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->assertCsrf($request);
        $board = $this->findBoard($id, $repository, ClassBoardVoter::EDIT);

        $name = mb_substr(trim(JsonRequestPayload::fromRequest($request)->string('name')), 0, 255);
        if ('' === $name) {
            return $this->json(['error' => 'empty_name', 'name' => $board->getName()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($naming->isTaken($this->currentUser(), $name, $board->getId())) {
            return $this->json(['error' => 'duplicate_name', 'name' => $board->getName()], Response::HTTP_CONFLICT);
        }

        $board->setName($name)->markModified();
        $entityManager->flush();

        return $this->json(['name' => $board->getName()]);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
