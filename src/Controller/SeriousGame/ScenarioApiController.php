<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Entity\SeriousGame;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\SeriousGameRepository;
use App\SeriousGame\SeriousGamePlayRefused;
use App\SeriousGame\SeriousGameScenarioPlay;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The SDK calls of the « scénario » mechanic (design/validated/serious-games-neko-con.md §3.3) -
 * getScenario, markActSeen, startTicket, submitStep, useHint, submitGuide, endTicket and the « Nouvelle
 * partie » - relayed by the host page like the common ones (GameApiController). A game of the
 * series mechanic has none of these routes: 404.
 *
 * The step answered is named by its identifier. A delivery's input is `{passed, failed}`: the code
 * itself never reaches the server (§3.4).
 */
#[RequiresFeature(Feature::SeriousGames)]
class ScenarioApiController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/api/{slug}/scenario', name: 'app_serious_games_api_scenario', methods: ['GET'])]
    public function board(string $slug, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->json($play->board($this->currentPlayer(), $this->scenarioGame($games, $slug)));
    }

    #[Route(path: '/serious-games/api/{slug}/scenario/act-seen', name: 'app_serious_games_api_scenario_act_seen', methods: ['POST'])]
    public function actSeen(Request $request, string $slug, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static function (SeriousGame $game, JsonRequestPayload $payload, User $player) use ($play): array {
            $play->markActSeen($player, $game, $payload->int('act') ?? 0);

            return ['ok' => true];
        });
    }

    #[Route(path: '/serious-games/api/{slug}/scenario/new-game', name: 'app_serious_games_api_scenario_new_game', methods: ['POST'])]
    public function newGame(Request $request, string $slug, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static function (SeriousGame $game, JsonRequestPayload $payload, User $player) use ($play): array {
            $play->newGame($player, $game);

            return ['ok' => true];
        });
    }

    #[Route(path: '/serious-games/api/{slug}/tickets', name: 'app_serious_games_api_ticket_start', methods: ['POST'])]
    public function start(Request $request, string $slug, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, User $player): array => $play->startTicket($player, $game, $payload->string('ticket')));
    }

    #[Route(path: '/serious-games/api/{slug}/ticket-sessions/{id}/step', name: 'app_serious_games_api_ticket_step', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function step(Request $request, string $slug, int $id, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, User $player): array => $play->submitStep($player, $play->ownedTicketSession($player, $game, $id), $payload->string('step'), $payload->toArray()['input'] ?? null));
    }

    #[Route(path: '/serious-games/api/{slug}/ticket-sessions/{id}/guide', name: 'app_serious_games_api_ticket_guide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function guide(Request $request, string $slug, int $id, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, User $player): array => $play->submitGuide($player, $play->ownedTicketSession($player, $game, $id), $payload->string('step'), $payload->toArray()['input'] ?? null));
    }

    #[Route(path: '/serious-games/api/{slug}/ticket-sessions/{id}/hint', name: 'app_serious_games_api_ticket_hint', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function hint(Request $request, string $slug, int $id, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, User $player): array => $play->useHint($player, $play->ownedTicketSession($player, $game, $id)));
    }

    #[Route(path: '/serious-games/api/{slug}/ticket-sessions/{id}/end', name: 'app_serious_games_api_ticket_end', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function end(Request $request, string $slug, int $id, SeriousGameRepository $games, SeriousGameScenarioPlay $play): JsonResponse
    {
        return $this->call($request, $games, $slug, static fn (SeriousGame $game, JsonRequestPayload $payload, User $player): array => $play->endTicket($player, $play->ownedTicketSession($player, $game, $id)));
    }

    private function scenarioGame(SeriousGameRepository $games, string $slug): SeriousGame
    {
        $game = $this->playableGame($games, $slug);
        if (!$game->isScenario()) {
            throw $this->createNotFoundException();
        }

        return $game;
    }

    /**
     * One write of the game - the same shape as GameApiController's.
     *
     * @param \Closure(SeriousGame, JsonRequestPayload, User): array<string, mixed> $call
     */
    private function call(Request $request, SeriousGameRepository $games, string $slug, \Closure $call): JsonResponse
    {
        $game = $this->scenarioGame($games, $slug);
        $this->assertCsrf($request);

        try {
            return $this->json($call($game, JsonRequestPayload::fromRequest($request), $this->currentPlayer()));
        } catch (SeriousGamePlayRefused $e) {
            return $this->json(['error' => $e->getMessage()], 'session-unknown' === $e->getMessage() ? Response::HTTP_NOT_FOUND : Response::HTTP_CONFLICT);
        }
    }
}
