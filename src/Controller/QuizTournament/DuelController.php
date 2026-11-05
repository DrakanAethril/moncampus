<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\User;
use App\Enum\Feature;
use App\Service\JsonRequestPayload;
use App\Service\QueryValue;
use App\Service\QuizTournament\QuizTournamentChallenges;
use App\Service\QuizTournament\QuizTournamentDuelEngine;
use App\Service\QuizTournament\QuizTournamentDuelReports;
use App\Service\QuizTournament\QuizTournamentLive;
use App\Service\QuizTournament\QuizTournamentRefusal;
use App\Service\UserAvatars;
use App\Util\NumericAnswerParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A duel and what follows it (design/validated/quiz-tournament.md §5 and §10): the duel screen
 * (H-question) with its state and its answers, the result or the defeat (H-resultat, H-defaite), the
 * correction (H-correction), and « Mes duels » (H-mes-duels).
 *
 * **A duel is its two players' alone**: anybody else, teachers and administrators included, gets a
 * 404 - whatever they may read of the tournament. A player keeps their duels and corrections even
 * once no longer eligible (§2: they keep their line). The result and the correction exist only once
 * the duel is finished; the duel screen of a finished duel goes to its result.
 *
 * Every gesture goes through QuizTournamentDuelEngine, which holds the clock and the end.
 */
#[RequiresFeature(Feature::QuizTournaments)]
class DuelController extends AbstractController
{
    use QuizTournamentControllerTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly QuizTournamentDuelEngine $engine,
        private readonly QuizTournamentDuelReports $reports,
        private readonly QuizTournamentLive $live,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/tournaments/{id}/duels/{duel}', name: 'app_quiz_tournament_duel', requirements: ['id' => '\d+', 'duel' => '\d+'], methods: ['GET'])]
    public function show(int $id, int $duel, Request $request, UserAvatars $avatars): Response
    {
        $row = $this->ownDuel($id, $duel);
        $player = $this->player();
        $now = $this->clock->now();
        $state = $this->engine->state($row, $player, $now);
        if ($row->isFinished()) {
            return $this->redirectToRoute('app_quiz_tournament_duel_result', ['id' => $id, 'duel' => $duel]);
        }

        $opponent = $row->opponentOf($player) ?? $row->getPlayerB();
        $tournament = $row->getTournament();

        return $this->render('quiz_tournament/duel.html.twig', [
            'tournament' => $tournament,
            'duel' => $row,
            'me' => $player,
            'opponent' => $opponent,
            'opponentName' => QuizTournamentChallenges::firstName($opponent),
            'avatars' => $avatars->forUsers([$player, $opponent]),
            'state' => $state,
            'duelTopic' => $this->live->duelTopic($row),
            'tournamentLive' => $this->live->screen($request, $tournament, $player, $now, [$this->live->duelTopic($row)]),
            'activeTab' => 'duels',
            'tournamentCrumbs' => $this->crumbs($row, 'quizTournamentDuelHeading', 'app_quiz_tournament_duel'),
        ]);
    }

    /** What the duel screen asks at each moment its clock says something changes, and every few seconds. */
    #[Route(path: '/tournaments/{id}/duels/{duel}/state', name: 'app_quiz_tournament_duel_state', requirements: ['id' => '\d+', 'duel' => '\d+'], methods: ['GET'])]
    public function state(int $id, int $duel): JsonResponse
    {
        return $this->json($this->engine->state($this->ownDuel($id, $duel), $this->player(), $this->clock->now()));
    }

    /** One answer: `{rank, choices: [ids]}`, or `{rank, value: "72", unit: "h"}` for a numérique. */
    #[Route(path: '/tournaments/{id}/duels/{duel}/answer', name: 'app_quiz_tournament_duel_answer', requirements: ['id' => '\d+', 'duel' => '\d+'], methods: ['POST'])]
    public function answer(int $id, int $duel, Request $request): JsonResponse
    {
        return $this->play($id, $duel, $request, $this->engine->answer(...));
    }

    /**
     * What is ticked or typed so far, not validated - the same body as an answer; it becomes the
     * answer when the player's time runs out (QuizTournamentDuelDraft).
     */
    #[Route(path: '/tournaments/{id}/duels/{duel}/draft', name: 'app_quiz_tournament_duel_draft', requirements: ['id' => '\d+', 'duel' => '\d+'], methods: ['POST'])]
    public function draft(int $id, int $duel, Request $request): JsonResponse
    {
        return $this->play($id, $duel, $request, $this->engine->draft(...));
    }

    #[Route(path: '/tournaments/{id}/duels/{duel}/result', name: 'app_quiz_tournament_duel_result', requirements: ['id' => '\d+', 'duel' => '\d+'], methods: ['GET'])]
    public function result(int $id, int $duel, Request $request): Response
    {
        $row = $this->finishedDuel($id, $duel);
        if (null === $row) {
            return $this->redirectToRoute('app_quiz_tournament_duel', ['id' => $id, 'duel' => $duel]);
        }
        $player = $this->player();
        $now = $this->clock->now();
        $tournament = $row->getTournament();
        $report = $this->reports->result($row, $player, $now);

        return $this->render('loss' === $report['outcome'] ? 'quiz_tournament/duel_defeat.html.twig' : 'quiz_tournament/duel_result.html.twig', [
            'tournament' => $tournament,
            'duel' => $row,
            'report' => $report,
            'opponentName' => QuizTournamentChallenges::firstName($row->opponentOf($player) ?? $row->getPlayerB()),
            'tournamentLive' => $this->live->screen($request, $tournament, $player, $now),
            'activeTab' => 'duels',
            'tournamentCrumbs' => $this->crumbs($row, 'quizTournamentDuelResultHeading', 'app_quiz_tournament_duel_result'),
        ]);
    }

    /**
     * H-correction: one question at a time (`?q=` its position in the list), « Toutes » or « Mes
     * erreurs » (`?filter=errors`), and a theme (`?theme=`, from the defeat's « Revoir »).
     */
    #[Route(path: '/tournaments/{id}/duels/{duel}/correction', name: 'app_quiz_tournament_duel_correction', requirements: ['id' => '\d+', 'duel' => '\d+'], methods: ['GET'])]
    public function correction(int $id, int $duel, Request $request): Response
    {
        $row = $this->finishedDuel($id, $duel);
        if (null === $row) {
            return $this->redirectToRoute('app_quiz_tournament_duel', ['id' => $id, 'duel' => $duel]);
        }
        $player = $this->player();
        $tournament = $row->getTournament();

        $all = $this->reports->corrections($row, $player);
        $errorsOnly = 'errors' === QueryValue::string($request, 'filter');
        $theme = QueryValue::trimmed($request, 'theme');
        $errors = array_values(array_filter($all, static fn (array $question): bool => $question['error']));
        $list = array_values(array_filter(
            $errorsOnly ? $errors : $all,
            static fn (array $question): bool => '' === $theme || $question['theme'] === $theme,
        ));
        $position = max(0, min(\count($list) - 1, QueryValue::int($request, 'q')));
        $opponent = $row->opponentOf($player) ?? $row->getPlayerB();

        return $this->render('quiz_tournament/duel_correction.html.twig', [
            'tournament' => $tournament,
            'duel' => $row,
            'question' => $list[$position] ?? null,
            'position' => $position,
            'count' => \count($list),
            'allCount' => \count($all),
            'errorCount' => \count($errors),
            'errorsOnly' => $errorsOnly,
            'theme' => $theme,
            'myScore' => $row->scoreOf($player) ?? 0.0,
            'theirScore' => $row->scoreOf($opponent) ?? 0.0,
            'opponentName' => QuizTournamentChallenges::firstName($opponent),
            'tournamentLive' => $this->live->screen($request, $tournament, $player, $this->clock->now()),
            'activeTab' => 'duels',
            'tournamentCrumbs' => $this->crumbs($row, 'quizTournamentCorrectionHeading', 'app_quiz_tournament_duel_correction'),
        ]);
    }

    /** H-mes-duels: a student's own duels in this tournament. */
    #[Route(path: '/tournaments/{id}/duels', name: 'app_quiz_tournament_duels', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(int $id, Request $request): Response
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        $player = $this->player();
        if (!\in_array('ROLE_STUDENT', $player->getRoles(), true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('quiz_tournament/duels.html.twig', [
            'tournament' => $tournament,
            'history' => $this->reports->history($tournament, $player),
            'tournamentLive' => $this->live->screen($request, $tournament, $player, $this->clock->now()),
            'activeTab' => 'duels',
            'tournamentCrumbs' => [['label' => $this->translator->trans('quizTournamentMyDuelsHeading'), 'url' => $this->generateUrl('app_quiz_tournament_duels', ['id' => $id])]],
        ]);
    }

    /** A duel of this tournament the current person is one of the two players of - else a 404. */
    /**
     * An answer or a draft: the body read, the engine's gesture run, a refusal answered 409 with the
     * state and its sentence.
     *
     * @param callable(QuizTournamentDuel, User, int, list<int>, float|null, string|null, \DateTimeImmutable): array<string, mixed> $gesture
     */
    private function play(int $id, int $duel, Request $request, callable $gesture): JsonResponse
    {
        if (!$this->isCsrfTokenValid('quiz_tournament_duel'.$duel, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $row = $this->ownDuel($id, $duel);
        $player = $this->player();
        $payload = JsonRequestPayload::fromRequest($request);

        $value = null;
        $unit = null;
        $raw = $payload->string('value');
        if ('' !== $raw) {
            $parsed = NumericAnswerParser::parse($raw);
            $value = $parsed['value'];
            $typedUnit = trim($payload->string('unit'));
            $unit = '' !== $typedUnit ? $typedUnit : $parsed['unit'];
        }

        try {
            return $this->json($gesture($row, $player, $payload->int('rank') ?? -1, $payload->ids('choices'), $value, $unit, $this->clock->now()));
        } catch (QuizTournamentRefusal $refusal) {
            return $this->json([
                ...$this->engine->state($row, $player, $this->clock->now()),
                'refusal' => $this->translator->trans($refusal->messageKey, $refusal->parameters),
            ], Response::HTTP_CONFLICT);
        }
    }

    private function ownDuel(int $id, int $duelId): QuizTournamentDuel
    {
        $tournament = $this->entityManager->getRepository(QuizTournament::class)->find($id);
        $duel = $this->entityManager->getRepository(QuizTournamentDuel::class)->find($duelId);
        if (null === $tournament || null === $duel || $duel->getTournament() !== $tournament || !$duel->involves($this->player())) {
            throw $this->createNotFoundException();
        }

        return $duel;
    }

    /** The duel once finished - brought up to now first, so a duel just over shows its result - or null. */
    private function finishedDuel(int $id, int $duelId): ?QuizTournamentDuel
    {
        $duel = $this->ownDuel($id, $duelId);
        if (!$duel->isFinished()) {
            $this->engine->state($duel, $this->player(), $this->clock->now());
        }

        return $duel->isFinished() ? $duel : null;
    }

    /** @return list<array{label: string, url: string}> */
    private function crumbs(QuizTournamentDuel $duel, string $labelKey, string $route): array
    {
        $id = $duel->getTournament()->getId();
        $crumbs = [];
        if (\in_array('ROLE_STUDENT', $this->player()->getRoles(), true)) {
            $crumbs[] = ['label' => $this->translator->trans('quizTournamentMyDuelsHeading'), 'url' => $this->generateUrl('app_quiz_tournament_duels', ['id' => $id])];
        }
        $crumbs[] = ['label' => $this->translator->trans($labelKey), 'url' => $this->generateUrl($route, ['id' => $id, 'duel' => $duel->getId()])];

        return $crumbs;
    }
}
