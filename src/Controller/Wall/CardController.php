<?php

declare(strict_types=1);

namespace App\Controller\Wall;

use App\Attribute\RequiresFeature;
use App\Entity\WallCard;
use App\Entity\WallList;
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
 * The cards of a wall (design/design_handoff_murs_collaboratifs, screens 3 to 5): adding one,
 * changing what it holds, dragging it, ticking its checklist, validating it, and the three
 * « Actions » of the open card - copy, move, duplicate - plus its deletion.
 *
 * Who may do what is the handoff's table, held by App\Service\Wall\WallAccess: changing, moving,
 * ticking and deleting are one right (WallVoter::CARD_EDIT); copying, duplicating and validating
 * belong to whoever runs the wall.
 */
#[RequiresFeature(Feature::Walls)]
#[IsGranted(new Expression(WallAccess::ELIGIBLE_EXPRESSION), statusCode: 404)]
class CardController extends AbstractController
{
    use WallControllerTrait;

    #[Route(path: '/walls/lists/{id}/cards', name: 'app_wall_card_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function add(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $list = $this->wallList($id, $entityManager, WallVoter::ADD_CARD);

        try {
            $writer->addCard($list, $this->currentUser(), JsonRequestPayload::fromRequest($request)->string('title'));
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($list->getWall());

        return $responder->state($list->getWall(), $this->currentUser(), $request);
    }

    // Writes the fields the page names and no other - a title, a text, a link, a label, a picture
    // or a file handed over as the token `/uploads/stage` answered.
    #[Route(path: '/walls/cards/{id}', name: 'app_wall_card_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager, WallVoter::CARD_EDIT);

        try {
            $writer->updateCard($card, JsonRequestPayload::fromRequest($request), $this->currentUser());
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request);
    }

    // The drop of a drag: the list it lands in, and the card it lands before (none: at the foot).
    #[Route(path: '/walls/cards/{id}/move', name: 'app_wall_card_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager, WallVoter::CARD_EDIT);
        $payload = JsonRequestPayload::fromRequest($request);
        $list = $entityManager->find(WallList::class, (int) $payload->int('list', 0));
        $before = null === $payload->int('before') ? null : $entityManager->find(WallCard::class, (int) $payload->int('before'));

        try {
            if (null === $list || (null !== $payload->int('before') && null === $before)) {
                throw new WallRefusal('wallInvalidRequestMessage');
            }
            $writer->moveCard($card, $list, $before);
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request);
    }

    #[Route(path: '/walls/cards/{id}/delete', name: 'app_wall_card_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager, WallVoter::CARD_EDIT);
        $wall = $card->getWall();

        $writer->deleteCard($card);
        $responder->commit($wall);

        return $responder->state($wall, $this->currentUser(), $request);
    }

    #[Route(path: '/walls/cards/{id}/duplicate', name: 'app_wall_card_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request, EntityManagerInterface $entityManager, WallCopier $copier, WallResponder $responder, TranslatorInterface $translator): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager);
        $this->denyAccessUnlessGranted(WallVoter::MANAGE, $card->getWall());

        $copier->duplicateCard($card, $this->currentUser());
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request, ['toast' => ['message' => $translator->trans('wallCardDuplicatedToast')]]);
    }

    /**
     * « Copier vers… » / « Déplacer vers… »: to the foot of a list, on this wall or on another.
     * Copying is a manager's gesture; moving is open to whoever may change the card. Either way
     * the destination must be a wall this person may add a card on - and it is that wall which
     * decides whether the card arrives awaiting validation.
     */
    #[Route(path: '/walls/cards/{id}/transfer', name: 'app_wall_card_transfer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transfer(int $id, Request $request, EntityManagerInterface $entityManager, WallCopier $copier, WallResponder $responder, TranslatorInterface $translator): JsonResponse
    {
        $this->assertCsrf($request);
        $payload = JsonRequestPayload::fromRequest($request);
        $moving = 'move' === $payload->string('mode');

        $card = $this->card($id, $entityManager, $moving ? WallVoter::CARD_EDIT : WallVoter::CARD_VIEW);
        $source = $card->getWall();
        if (!$moving) {
            $this->denyAccessUnlessGranted(WallVoter::MANAGE, $source);
        }
        $target = $this->wallList((int) $payload->int('list', 0), $entityManager, WallVoter::ADD_CARD);

        if ($moving) {
            $copier->moveCard($card, $target, $this->currentUser());
        } else {
            $copier->copyCard($card, $target, $this->currentUser());
        }
        $responder->commit(...($source === $target->getWall() ? [$source] : [$source, $target->getWall()]));

        return $responder->state($source, $this->currentUser(), $request, ['toast' => [
            'message' => $translator->trans($moving ? 'wallCardMovedToast' : 'wallCardCopiedToast', ['%wall%' => $target->getWall()->getTitle(), '%list%' => $target->getTitle()]),
            'url' => $this->generateUrl('app_wall_show', ['id' => $target->getWall()->getId()]),
        ]]);
    }

    #[Route(path: '/walls/cards/{id}/approve', name: 'app_wall_card_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager);
        $this->denyAccessUnlessGranted(WallVoter::MANAGE, $card->getWall());

        $writer->approve($card);
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request);
    }

    // « Refuser » removes the card: there is no refused state to come back from.
    #[Route(path: '/walls/cards/{id}/reject', name: 'app_wall_card_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager);
        $wall = $card->getWall();
        $this->denyAccessUnlessGranted(WallVoter::MANAGE, $wall);
        if (!$card->isPending()) {
            throw $this->createNotFoundException();
        }

        $writer->deleteCard($card);
        $responder->commit($wall);

        return $responder->state($wall, $this->currentUser(), $request);
    }

    #[Route(path: '/walls/cards/{id}/checklist', name: 'app_wall_card_checklist_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addChecklistItem(int $id, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager, WallVoter::CARD_EDIT);

        try {
            $writer->addChecklistItem($card, JsonRequestPayload::fromRequest($request)->string('text'));
        } catch (WallRefusal $refusal) {
            return $responder->refusal($refusal);
        }
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request);
    }

    #[Route(path: '/walls/cards/{id}/checklist/{item}/toggle', name: 'app_wall_card_checklist_toggle', methods: ['POST'], requirements: ['id' => '\d+', 'item' => '[0-9a-f]{8}'])]
    public function toggleChecklistItem(int $id, string $item, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager, WallVoter::CARD_EDIT);

        $writer->toggleChecklistItem($card, $item);
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request);
    }

    #[Route(path: '/walls/cards/{id}/checklist/{item}/delete', name: 'app_wall_card_checklist_delete', methods: ['POST'], requirements: ['id' => '\d+', 'item' => '[0-9a-f]{8}'])]
    public function removeChecklistItem(int $id, string $item, Request $request, EntityManagerInterface $entityManager, WallWriter $writer, WallResponder $responder): JsonResponse
    {
        $this->assertCsrf($request);
        $card = $this->card($id, $entityManager, WallVoter::CARD_EDIT);

        $writer->removeChecklistItem($card, $item);
        $responder->commit($card->getWall());

        return $responder->state($card->getWall(), $this->currentUser(), $request);
    }
}
