<?php

declare(strict_types=1);

namespace App\Controller\Wall;

use App\Attribute\RequiresFeature;
use App\Entity\Wall;
use App\Enum\Feature;
use App\Security\Voter\WallVoter;
use App\Service\JsonRequestPayload;
use App\Service\Wall\WallAccess;
use App\Service\Wall\WallCopier;
use App\Service\Wall\WallRefusal;
use App\Service\Wall\WallResponder;
use App\Service\Wall\WallWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The lists of a wall - the `⋯` menu of the handoff's screen 3 and « + Ajouter une liste ». Every
 * gesture here belongs to whoever runs the wall (WallVoter::MANAGE); a participant has none of them.
 */
#[RequiresFeature(Feature::Walls)]
#[IsGranted(new Expression(WallAccess::ELIGIBLE_EXPRESSION), statusCode: 404)]
class WallListController extends AbstractController
{
    use WallControllerTrait;

    #[Route(path: '/walls/{id}/lists', name: 'app_wall_list_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function add(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $wall = $this->wall($id, $entityManager, WallVoter::MANAGE);

        try {
            $writer->addList($wall, JsonRequestPayload::fromRequest($request)->string('title'));
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($wall);

        return $responder->state($wall, $this->currentUser(), $request);
    }

    #[Route(path: '/walls/lists/{id}/rename', name: 'app_wall_list_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rename(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $list = $this->wallList($id, $entityManager);

        try {
            $writer->renameList($list, JsonRequestPayload::fromRequest($request)->string('title'));
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($list->getWall());

        return $responder->state($list->getWall(), $this->currentUser(), $request);
    }

    // The seven swatches and the free picker write the same way: one colour, `#rrggbb`.
    #[Route(path: '/walls/lists/{id}/color', name: 'app_wall_list_color', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function color(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $list = $this->wallList($id, $entityManager);

        try {
            $writer->colorList($list, JsonRequestPayload::fromRequest($request)->string('color'));
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($list->getWall());

        return $responder->state($list->getWall(), $this->currentUser(), $request);
    }

    #[Route(path: '/walls/lists/{id}/duplicate', name: 'app_wall_list_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request, EntityManagerInterface $entityManager, WallCopier $copier, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $list = $this->wallList($id, $entityManager);

        $copier->duplicateList($list, $this->currentUser());
        $responder->commit($list->getWall());

        return $responder->state($list->getWall(), $this->currentUser(), $request);
    }

    #[Route(path: '/walls/lists/{id}/delete', name: 'app_wall_list_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $list = $this->wallList($id, $entityManager);
        $wall = $list->getWall();

        $writer->deleteList($list);
        $responder->commit($wall);

        return $responder->state($wall, $this->currentUser(), $request);
    }

    // « Copier la liste vers… » and its « Déplacer » twin: the list and every card of it, to the
    // end of a wall this person also runs.
    #[Route(path: '/walls/lists/{id}/transfer', name: 'app_wall_list_transfer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transfer(int $id, Request $request, EntityManagerInterface $entityManager, WallCopier $copier, WallResponder $responder, TranslatorInterface $translator): JsonResponse
    {
        $this->assertCsrf($request);
        $list = $this->wallList($id, $entityManager);
        $source = $list->getWall();
        $payload = JsonRequestPayload::fromRequest($request);
        $target = $this->wall((int) $payload->int('wall', 0), $entityManager, WallVoter::MANAGE);
        $moving = 'move' === $payload->string('mode');

        if ($moving) {
            $copier->moveList($list, $target, $this->currentUser());
        } else {
            $copier->copyList($list, $target, $this->currentUser());
        }
        $responder->commit(...$this->distinct($source, $target));

        return $responder->state($source, $this->currentUser(), $request, ['toast' => [
            'message' => $translator->trans($moving ? 'wallListMovedToast' : 'wallListCopiedToast', ['%wall%' => $target->getTitle()]),
            'url' => $this->generateUrl('app_wall_show', ['id' => $target->getId()]),
        ]]);
    }

    /** @return list<Wall> */
    private function distinct(Wall $source, Wall $target): array
    {
        return $source === $target ? [$source] : [$source, $target];
    }
}
