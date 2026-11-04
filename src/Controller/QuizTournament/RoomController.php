<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTournamentChallenge;
use App\Enum\Feature;
use App\Service\PostValue;
use App\Service\QuizTournament\QuizTournamentChallenges;
use App\Service\QuizTournament\QuizTournamentLive;
use App\Service\QuizTournament\QuizTournamentRefusal;
use App\Service\QuizTournament\QuizTournamentRooms;
use App\Service\QuizTournament\QuizTournamentStandingsAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A duel's room (design/validated/quiz-tournament.md §4.3, H-salle): the two players, the time the
 * room has left, the duel's rules, « Je suis prêt ». The page beats every 20 seconds while its
 * player is ready (POST …/ready), says when it leaves (POST …/leave, a beacon), and follows the
 * other player over Mercure (QuizTournamentLive::roomTopic()). When both are ready, the request that
 * sees it starts the duel (QuizTournamentDuelStarter) and both pages go to the duel.
 *
 * Only the room's two players reach it (404 for anyone else); the gestures are PLAY's
 * (QuizTournamentRooms reads it again for both).
 */
#[RequiresFeature(Feature::QuizTournaments)]
class RoomController extends AbstractController
{
    use QuizTournamentControllerTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly QuizTournamentRooms $rooms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/tournaments/{id}/rooms/{challenge}', name: 'app_quiz_tournament_room', requirements: ['id' => '\d+', 'challenge' => '\d+'], methods: ['GET'])]
    public function show(int $id, int $challenge, Request $request, QuizTournamentLive $live, QuizTournamentStandingsAssembler $assembler): Response
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        $room = $this->ownChallenge($tournament, $challenge, $this->entityManager);
        $now = $this->clock->now();
        $player = $this->player();

        $duel = $room->getDuel();
        if (null !== $duel) {
            return $this->redirectToRoute('app_quiz_tournament_duel', ['id' => $id, 'duel' => $duel->getId()]);
        }
        if (!$room->isRoomOpen($now)) {
            if ($room->settle($now)) {
                $this->entityManager->flush();
            }
            $this->addFlash('warning', $this->translator->trans('quizTournamentRoomClosedFlash'));

            return $this->redirectToRoute('app_quiz_tournament_challenges', ['id' => $id]);
        }

        $opponent = $room->opponentOf($player) ?? $room->getChallenged();
        $standings = $assembler->assemble($tournament, $now);

        return $this->render('quiz_tournament/room.html.twig', [
            'tournament' => $tournament,
            'room' => $room,
            'me' => $player,
            'opponent' => $opponent,
            'myLine' => $standings->lineFor($player->getId()),
            'opponentLine' => $standings->lineFor($opponent->getId()),
            'opponentName' => QuizTournamentChallenges::firstName($opponent),
            'state' => $this->rooms->state($room, $player, $now),
            'roomTopic' => $live->roomTopic($room),
            'tournamentLive' => $live->screen($request, $tournament, $player, $now, [$live->roomTopic($room)]),
            'activeTab' => 'challenges',
            'tournamentCrumbs' => [
                ['label' => $this->translator->trans('quizTournamentChallengesHeading'), 'url' => $this->generateUrl('app_quiz_tournament_challenges', ['id' => $id])],
                ['label' => $this->translator->trans('quizTournamentRoomHeading'), 'url' => $this->generateUrl('app_quiz_tournament_room', ['id' => $id, 'challenge' => $challenge])],
            ],
        ]);
    }

    /** « Je suis prêt », then the heartbeat every 20 s. Answers the room's state, the duel's address once it started. */
    #[Route(path: '/tournaments/{id}/rooms/{challenge}/ready', name: 'app_quiz_tournament_room_ready', requirements: ['id' => '\d+', 'challenge' => '\d+'], methods: ['POST'])]
    public function ready(int $id, int $challenge, Request $request): JsonResponse
    {
        $room = $this->room($id, $challenge, $request->headers->get('X-CSRF-Token'));
        $player = $this->player();
        $now = $this->clock->now();

        try {
            $this->rooms->ready($room, $player, $now);
        } catch (QuizTournamentRefusal $refusal) {
            return $this->json([
                ...$this->rooms->state($room, $player, $now),
                'refusal' => $this->translator->trans($refusal->messageKey, $refusal->parameters),
            ], Response::HTTP_CONFLICT);
        }

        return $this->json($this->rooms->state($room, $player, $now));
    }

    /** The page is left (a beacon on `pagehide`), or « Quitter la salle »: one is no longer ready. */
    #[Route(path: '/tournaments/{id}/rooms/{challenge}/leave', name: 'app_quiz_tournament_room_leave', requirements: ['id' => '\d+', 'challenge' => '\d+'], methods: ['POST'])]
    public function leave(int $id, int $challenge, Request $request): Response
    {
        $room = $this->room($id, $challenge, PostValue::string($request, '_token'));
        $this->rooms->leave($room, $this->player(), $this->clock->now());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** What the page asks while its player is not ready - the other one may have come in. */
    #[Route(path: '/tournaments/{id}/rooms/{challenge}/state', name: 'app_quiz_tournament_room_state', requirements: ['id' => '\d+', 'challenge' => '\d+'], methods: ['GET'])]
    public function state(int $id, int $challenge): JsonResponse
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        $room = $this->ownChallenge($tournament, $challenge, $this->entityManager);

        return $this->json($this->rooms->state($room, $this->player(), $this->clock->now()));
    }

    private function room(int $id, int $challenge, ?string $token): QuizTournamentChallenge
    {
        if (!$this->isCsrfTokenValid('quiz_tournament_room'.$challenge, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $tournament = $this->viewableTournament($id, $this->entityManager);

        return $this->ownChallenge($tournament, $challenge, $this->entityManager);
    }
}
