<?php

declare(strict_types=1);

namespace App\Controller\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallComment;
use App\Entity\WallList;
use App\Security\Voter\WallVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The doors the wall controllers share (design/design_handoff_murs_collaboratifs).
 *
 * A wall, a list or a card this person may not *read* answers 404, never 403: it does not exist
 * for them. Once it is readable, a gesture they are not allowed is a plain 403 - the screen never
 * offers it, so it was typed by hand.
 */
trait WallControllerTrait
{
    public const string CSRF_TOKEN_ID = 'wall';

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function wall(int $id, EntityManagerInterface $entityManager, string $attribute = WallVoter::VIEW): Wall
    {
        $wall = $entityManager->find(Wall::class, $id);
        if (null === $wall || !$this->isGranted(WallVoter::VIEW, $wall)) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted($attribute, $wall);

        return $wall;
    }

    /** A list is reached through its wall: `$attribute` is asked of the wall. */
    private function wallList(int $id, EntityManagerInterface $entityManager, string $attribute = WallVoter::MANAGE): WallList
    {
        $list = $entityManager->find(WallList::class, $id);
        if (null === $list || !$this->isGranted(WallVoter::VIEW, $list->getWall())) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted($attribute, $list->getWall());

        return $list;
    }

    private function card(int $id, EntityManagerInterface $entityManager, string $attribute = WallVoter::CARD_VIEW): WallCard
    {
        $card = $entityManager->find(WallCard::class, $id);
        if (null === $card || !$this->isGranted(WallVoter::CARD_VIEW, $card)) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted($attribute, $card);

        return $card;
    }

    private function comment(int $id, EntityManagerInterface $entityManager): WallComment
    {
        $comment = $entityManager->find(WallComment::class, $id);
        if (null === $comment || !$this->isGranted(WallVoter::CARD_VIEW, $comment->getCard())) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(WallVoter::COMMENT_DELETE, $comment);

        return $comment;
    }

    /** The page's fetches carry the token in a header; the two plain forms carry it as a field. */
    private function assertCsrf(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
