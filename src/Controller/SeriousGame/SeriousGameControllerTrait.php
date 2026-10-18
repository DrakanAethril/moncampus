<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\User;
use App\Repository\SeriousGameRepository;
use App\Security\Voter\SeriousGameVoter;
use App\Service\SeriousGame\SeriousGameAccess;

/**
 * What every serious-game controller asks first (design/validated/serious-games.md §3). A refusal
 * is a **404**, never a 403: a game one may not play does not exist for them.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
 */
trait SeriousGameControllerTrait
{
    private const string CSRF_TOKEN_ID = 'serious-games';

    private function currentPlayer(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    /** The module's own screens - catalogue, avatar, player page - open to whoever has a game. */
    private function assertHasAPlayableGame(SeriousGameAccess $access): User
    {
        $user = $this->currentPlayer();
        if (!$access->hasPlayableGame($user)) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    /** The serious games' one CSRF token: every write of the module is a fetch() from its screens. */
    private function assertCsrf(\Symfony\Component\HttpFoundation\Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function playableGame(SeriousGameRepository $games, string $slug): SeriousGame
    {
        $game = $games->findOneBySlug($slug);
        if (null === $game || !$this->isGranted(SeriousGameVoter::PLAY, $game)) {
            throw $this->createNotFoundException();
        }

        return $game;
    }
}
