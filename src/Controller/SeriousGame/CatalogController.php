<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\SeriousGame\Avatar\SeriousGameAvatars;
use App\Service\SeriousGame\SeriousGameAccess;
use App\Service\SeriousGame\SeriousGameShelf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalogue of the serious games (design/validated/serious-games.md §8,
 * design/conception_serious_games/catalogue.html): the games playable for the person, on **one
 * shelf**, no recommendation, filtered by subject, level and progress. A student reaches it by its
 * address alone - there is no link in their menus (§1.12) - and finds their player page from it; a
 * teacher plays without collecting and finds the results of their students from it.
 */
#[RequiresFeature(Feature::SeriousGames)]
class CatalogController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games', name: 'app_serious_games', methods: ['GET'])]
    public function index(SeriousGameAccess $access, SeriousGameShelf $shelf, SeriousGameAvatars $avatars): Response
    {
        $user = $this->assertHasAPlayableGame($access);
        $collects = $access->canCollect($user);
        $cards = $shelf->cardsFor($user);

        $subjects = array_values(array_unique(array_merge(...array_map(static fn (array $c): array => $c['game']->getSubjects(), $cards) ?: [[]])));
        $levels = array_values(array_unique(array_merge(...array_map(static fn (array $c): array => $c['game']->getLevels(), $cards) ?: [[]])));

        return $this->render('serious_game/catalog.html.twig', [
            'cards' => $cards,
            'subjects' => $subjects,
            'levels' => $levels,
            'collects' => $collects,
            'avatar_config' => $avatars->configFor($user),
            'avatar_drawn' => $avatars->hasOne($user),
            'readsResults' => $this->isGranted('ROLE_TEACHER') || $this->isGranted('ROLE_ADMIN'),
        ]);
    }
}
