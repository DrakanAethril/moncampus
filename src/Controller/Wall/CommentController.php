<?php

declare(strict_types=1);

namespace App\Controller\Wall;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Security\Voter\WallVoter;
use App\Service\JsonRequestPayload;
use App\Service\Wall\WallAccess;
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

/**
 * The comments under an open card. They exist only while the wall's « Commentaires » switch is on:
 * with it off the voter refuses both gestures, so the switch has an effect on the server and not
 * only on what the dialog draws.
 */
#[RequiresFeature(Feature::Walls)]
#[IsGranted(new Expression(WallAccess::ELIGIBLE_EXPRESSION), statusCode: 404)]
class CommentController extends AbstractController
{
    use WallControllerTrait;

    #[Route(path: '/walls/cards/{id}/comments', name: 'app_wall_comment_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function add(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager, WallVoter::CARD_COMMENT);

        try {
            $writer->comment($card, $this->currentUser(), JsonRequestPayload::fromRequest($request)->string('text'));
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request);
    }

    // Its author's to withdraw, and the wall's managers' to remove.
    #[Route(path: '/walls/comments/{id}/delete', name: 'app_wall_comment_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $comment = $this->comment($id, $entityManager);
        $wall = $comment->getCard()->getWall();

        $writer->deleteComment($comment);
        $responder->commit($wall);

        return $responder->state($wall, $this->currentUser(), $request);
    }
}
