<?php

declare(strict_types=1);

namespace App\Controller\ClassBoard;

use App\Attribute\RequiresFeature;
use App\Entity\ClassBoard;
use App\Enum\ClassBoardWidgetType;
use App\Enum\Feature;
use App\Repository\ClassBoardRepository;
use App\Security\Voter\ClassBoardVoter;
use App\Service\ClassBoard\ClassBoardLayout;
use App\Service\ClassBoard\ClassBoardView;
use App\Service\ClassBoard\InvalidClassBoardLayoutException;
use App\Service\JsonRequestPayload;
use App\Service\QueryValue;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The widgets of a board, drawn by the server one at a time: a widget the dock adds, and a widget
 * redrawn after its source changed (a saved draw chosen, a lot of groups). Neither writes the
 * board - the page saves the whole layout afterwards, through BoardController::save().
 */
#[RequiresFeature(Feature::ClassTools)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'), statusCode: 404)]
class WidgetController extends AbstractController
{
    use ClassBoardControllerTrait;

    #[Route(path: '/tools/boards/{id}/widgets', name: 'app_class_board_widget_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function new(int $id, Request $request, ClassBoardRepository $repository, ClassBoardLayout $layout, ClassBoardView $view): Response
    {
        $this->assertCsrf($request);
        $board = $this->findBoard($id, $repository, ClassBoardVoter::EDIT);
        $payload = JsonRequestPayload::fromRequest($request);

        $type = ClassBoardWidgetType::tryFrom($payload->string('type'));
        if (null === $type || !$view->offered($type, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        try {
            $widget = $layout->newWidget($type, $payload->string('id'), $payload->int('count', 0) ?? 0, $payload->int('z', 1) ?? 1);
        } catch (InvalidClassBoardLayoutException) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderWidget($board, $view->widget($board, $widget));
    }

    #[Route(path: '/tools/boards/{id}/widgets/render', name: 'app_class_board_widget_render', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function redraw(int $id, Request $request, ClassBoardRepository $repository, ClassBoardLayout $layout, ClassBoardView $view): Response
    {
        $this->assertCsrf($request);
        $board = $this->findBoard($id, $repository, ClassBoardVoter::EDIT);

        try {
            $widgets = $layout->normalize([JsonRequestPayload::fromRequest($request)->object('widget')->toArray()], $board->getLayout());
        } catch (InvalidClassBoardLayoutException) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderWidget($board, $view->widget($board, $widgets[0]));
    }

    // The QR code of an address typed in the widget, drawn here rather than by a script in the
    // page: the platform already carries the library, and the address never leaves for a third
    // party to draw it.
    #[Route(path: '/tools/boards/qr-code', name: 'app_class_board_qr_code', methods: ['GET'])]
    public function qrCode(Request $request): Response
    {
        $text = mb_substr(QueryValue::trimmed($request, 'text'), 0, ClassBoardLayout::URL_MAX);
        if ('' === $text) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $svg = (new Builder(writer: new SvgWriter(), data: $text, size: 600, margin: 0))->build()->getString();

        return new Response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * @param array{widget: array<string, mixed>, type: ClassBoardWidgetType, data: array<string, mixed>}|null $view
     */
    private function renderWidget(ClassBoard $board, ?array $view): Response
    {
        if (null === $view) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('class_board/_widget.html.twig', ['board' => $board, 'view' => $view]);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(BoardController::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
