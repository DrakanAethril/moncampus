<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Enum\WallFormat;
use App\Service\FileUploadService;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * What every action on a wall answers, and the one place a change is committed.
 *
 * The answer is always the same document - the wall as *this* person now reads it: its revision,
 * its title, the canvas drawn in the view their browser is in, and the card they have open if they
 * have one. The page applies it whole (assets/controllers/wall_controller.js), so a change made by
 * a colleague, a switch flipped in the settings and one's own gesture all arrive the same way.
 *
 * The browser says which view it is in and which card it has open (`?view=` and `?card=`); neither
 * is trusted for more than drawing - a card this person may not read comes back as `card: null`,
 * which closes it.
 */
final class WallResponder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WallBoardView $view,
        private readonly WallAccess $access,
        private readonly WallLiveNotifier $notifier,
        private readonly FileUploadService $files,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Moves the wall's revision, writes, then tells whoever is watching. */
    public function commit(Wall ...$walls): void
    {
        foreach ($walls as $wall) {
            $wall->touch();
        }
        $this->entityManager->flush();
        foreach ($walls as $wall) {
            $this->notifier->publish($wall);
        }
    }

    /** @param array<string, mixed> $extra */
    public function state(Wall $wall, User $user, Request $request, array $extra = []): JsonResponse
    {
        $board = $this->view->build($wall, $user);
        $format = WallFormat::tryFrom(QueryValue::string($request, 'view')) ?? $wall->getFormat();

        $state = [
            'revision' => $wall->getRevision(),
            // Echoed so that the page can tell an answer drawn for a view it has since left.
            'view' => $format->value,
            'title' => $wall->getTitle(),
            'mayAdd' => $board['mayAdd'],
            'settings' => $this->settings($wall),
            'board' => $this->twig->render('wall/_board.html.twig', ['board' => $board, 'view' => $format]),
        ];

        $cardId = QueryValue::nullableInt($request, 'card');
        if (null !== $cardId) {
            $card = $this->entityManager->find(WallCard::class, $cardId);
            $state['card'] = null !== $card && $card->getWall() === $wall && $this->access->maySeeCard($card, $user)
                ? $this->card($card, $user)
                : null;
        }

        return new JsonResponse([...$state, ...$extra]);
    }

    /**
     * « Paramètres du mur » as they stand, so that the panel open in one manager's browser follows
     * a switch flipped in another's. Nothing here is a secret: every one of them shows on the wall.
     *
     * @return array<string, bool|string|null>
     */
    public function settings(Wall $wall): array
    {
        return [
            'title' => $wall->getTitle(),
            'backgroundColor' => $wall->getBackgroundColor(),
            'backgroundImage' => null === $wall->getBackgroundImageKey() ? null : $this->files->url($wall->getBackgroundImageKey()),
            'authorsShown' => $wall->areAuthorsShown(),
            'labelsShown' => $wall->areLabelsShown(),
            'countsShown' => $wall->areCountsShown(),
            'commentsEnabled' => $wall->areCommentsEnabled(),
            'participantsMayAdd' => $wall->mayParticipantsAdd(),
            'participantsMayEditOthers' => $wall->mayParticipantsEditOthers(),
            'moderated' => $wall->isModerated(),
        ];
    }

    public function card(WallCard $card, User $user): string
    {
        $wall = $card->getWall();

        return $this->twig->render('wall/_card_modal.html.twig', [
            'card' => $card,
            'wall' => $wall,
            'editable' => $this->access->mayEditCard($card, $user),
            'manages' => $this->access->mayManage($wall, $user),
            'mayComment' => $this->access->mayComment($card, $user),
            'viewer' => $user,
        ]);
    }

    public function refusal(WallRefusal $refusal): JsonResponse
    {
        return new JsonResponse([
            'error' => $refusal->getMessage(),
            'message' => $refusal->translated ? $refusal->getMessage() : $this->translator->trans($refusal->getMessage(), $refusal->parameters),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
