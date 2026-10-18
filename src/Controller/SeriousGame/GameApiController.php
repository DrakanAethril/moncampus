<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Entity\SeriousGame;
use App\Enum\Feature;
use App\Repository\SeriousGameRepository;
use App\SeriousGame\SeriousGamePlay;
use App\SeriousGame\SeriousGamePlayRefused;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The SDK of the serious games (design/validated/serious-games.md §6.2): the JSON the host page
 * relays for the game. The game itself never calls it - it runs in a sandboxed frame with an opaque
 * origin and talks to its host page through `postMessage`; the host (serious_game_host_controller.js)
 * calls these routes with the session and the CSRF token.
 *
 * Every route asks PLAY on the game, every session is the player's own, and a refusal of the game
 * is a 409 carrying its code. **The answer of a question is never sent before it is answered**, and
 * during the final test not before the end.
 */
#[RequiresFeature(Feature::SeriousGames)]
class GameApiController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/api/{slug}/user', name: 'app_serious_games_api_user', methods: ['GET'])]
    public function user(string $slug, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        $this->playableGame($games, $slug);

        return $this->json($play->user($this->currentPlayer()));
    }

    #[Route(path: '/serious-games/api/{slug}/progress', name: 'app_serious_games_api_progress', methods: ['GET'])]
    public function progress(string $slug, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        $game = $this->playableGame($games, $slug);

        return $this->json($play->progress($this->currentPlayer(), $game));
    }

    #[Route(path: '/serious-games/api/{slug}/progress', name: 'app_serious_games_api_save_progress', methods: ['POST'])]
    public function saveProgress(Request $request, string $slug, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static function (SeriousGame $game, JsonRequestPayload $payload, $player) use ($play): array {
            $play->saveProgress($player, $game, $payload->object('data')->toArray());

            return ['saved' => true];
        });
    }

    #[Route(path: '/serious-games/api/{slug}/series', name: 'app_serious_games_api_series', methods: ['POST'])]
    public function startSeries(Request $request, string $slug, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, $player): array => $play->startSeries($player, $game, $payload->string('unit')));
    }

    #[Route(path: '/serious-games/api/{slug}/final', name: 'app_serious_games_api_final', methods: ['POST'])]
    public function startFinal(Request $request, string $slug, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, $player): array => $play->startFinal($player, $game));
    }

    #[Route(path: '/serious-games/api/{slug}/comets', name: 'app_serious_games_api_comet', methods: ['POST'])]
    public function studyComet(Request $request, string $slug, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, $player): array => $play->studyComet($player, $game, $payload->string('misconception')));
    }

    #[Route(path: '/serious-games/api/{slug}/sessions/{id}/next', name: 'app_serious_games_api_next', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function next(Request $request, string $slug, int $id, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, $player): array => $play->nextQuestion($player, $play->ownedSession($player, $game, $id)));
    }

    #[Route(path: '/serious-games/api/{slug}/sessions/{id}/answer', name: 'app_serious_games_api_answer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function answer(Request $request, string $slug, int $id, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, $player): array => $play->answer($player, $play->ownedSession($player, $game, $id), mb_substr($payload->string('input'), 0, 500)));
    }

    #[Route(path: '/serious-games/api/{slug}/sessions/{id}/end', name: 'app_serious_games_api_end', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function end(Request $request, string $slug, int $id, SeriousGameRepository $games, SeriousGamePlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, $player): array => $play->end($player, $play->ownedSession($player, $game, $id)));
    }

    /**
     * One write of the game: the game playable, the token right, the body read once, a refusal of
     * the game turned into its code.
     *
     * @param \Closure(SeriousGame, JsonRequestPayload, \App\Entity\User): array<string, mixed> $call
     */
    private function call(Request $request, SeriousGameRepository $games, string $slug, \Closure $call): JsonResponse
    {
        $game = $this->playableGame($games, $slug);
        $this->assertCsrf($request);

        try {
            return $this->json($call($game, JsonRequestPayload::fromRequest($request), $this->currentPlayer()));
        } catch (SeriousGamePlayRefused $e) {
            return $this->json(['error' => $e->getMessage()], 'session-unknown' === $e->getMessage() ? Response::HTTP_NOT_FOUND : Response::HTTP_CONFLICT);
        }
    }
}
