<?php

declare(strict_types=1);

namespace App\Controller\Wall;

use App\Attribute\RequiresFeature;
use App\Entity\Wall;
use App\Enum\Feature;
use App\Enum\WallFormat;
use App\Repository\WallRepository;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\Wall\WallAccess;
use App\Service\Wall\WallAudience;
use App\Service\Wall\WallTimeLabel;
use App\Service\Wall\WallWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Murs collaboratifs », the list (design/design_handoff_murs_collaboratifs, screens 1 and 2):
 * « Mes murs », « Partagés avec moi », and the « Nouveau mur » dialog. There are no template walls -
 * the handoff's third tab was dropped at the establishment's request.
 *
 * Whoever is neither personnel nor a student is answered 404 even where the feature has been lit
 * for them: a tutor has no walls, the screen does not exist for them.
 */
#[RequiresFeature(Feature::Walls)]
#[IsGranted(new Expression(WallAccess::ELIGIBLE_EXPRESSION), statusCode: 404)]
class ListController extends AbstractController
{
    use WallControllerTrait;

    #[Route(path: '/walls', name: 'app_walls', methods: ['GET'])]
    public function index(Request $request, WallRepository $walls, WallAudience $audience, WallTimeLabel $timeLabel): Response
    {
        $user = $this->currentUser();
        $shared = 'shared' === QueryValue::string($request, 'tab');
        $now = new \DateTimeImmutable();

        return $this->render('wall/index.html.twig', [
            'shared' => $shared,
            'tiles' => array_map(static fn (Wall $wall): array => [
                'wall' => $wall,
                'meta' => $shared ? ($wall->getOwner()->getDisplayName() ?? $wall->getOwner()->getUsername()) : $timeLabel->ago($wall->getUpdatedAt(), $now),
            ], $shared ? $walls->findSharedWith($user) : $walls->findOwnedBy($user)),
            'formats' => WallFormat::cases(),
            'classes' => $audience->classesFor($user),
        ]);
    }

    // A plain form, answered by a redirect onto the new wall - « À la création, le mur s'ouvre ».
    #[Route(path: '/walls', name: 'app_wall_create', methods: ['POST'])]
    public function create(Request $request, WallWriter $writer, WallAudience $audience, EntityManagerInterface $entityManager): Response
    {
        $this->assertCsrf($request);

        $wall = $writer->create(
            $this->currentUser(),
            PostValue::string($request, 'title'),
            WallFormat::tryFrom(PostValue::string($request, 'format')) ?? WallFormat::Columns,
        );
        $audience->share($wall, PostValue::intList($request, 'programs'), PostValue::intList($request, 'members'));
        $entityManager->flush();

        return $this->redirectToRoute('app_wall_show', ['id' => $wall->getId()]);
    }
}
