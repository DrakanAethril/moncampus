<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTournamentChallenge;
use App\Entity\User;
use App\Enum\Feature;
use App\Service\PostValue;
use App\Service\QuizTournament\QuizTournamentChallengeRules;
use App\Service\QuizTournament\QuizTournamentChallenges;
use App\Service\QuizTournament\QuizTournamentElo;
use App\Service\QuizTournament\QuizTournamentLive;
use App\Service\QuizTournament\QuizTournamentRefusal;
use App\Service\QuizTournament\QuizTournamentStandingsAssembler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A tournament's challenges (design/validated/quiz-tournament.md §4.1, §4.2 and §7): « Mes défis »,
 * the « Défier » sheet that shows the enjeu before sending (H-defier), and the gestures - send,
 * accept, refuse. QuizTournamentChallenges reads every rule again server-side; a refusal comes back
 * as the sentence it names.
 *
 * Reading « Mes défis » is QuizTournamentVoter::VIEW for a student (a challenge's outcome stays
 * readable once the tournament is over; nobody else has challenges); sending and accepting are PLAY. Nothing is sent to anybody: the challenged
 * finds the challenge here.
 */
#[RequiresFeature(Feature::QuizTournaments)]
class ChallengeController extends AbstractController
{
    use QuizTournamentControllerTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly QuizTournamentChallenges $challenges,
        private readonly QuizTournamentStandingsAssembler $assembler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/tournaments/{id}/challenges', name: 'app_quiz_tournament_challenges', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id, Request $request, QuizTournamentLive $live): Response
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        // A student's screen: the teachers and the administration read the standings, not challenges.
        if (!$this->isGranted('ROLE_STUDENT')) {
            throw $this->createNotFoundException();
        }
        $reader = $this->player();
        $now = $this->clock->now();
        $board = $this->challenges->board($tournament, $reader, $now);
        $standings = $this->assembler->assemble($tournament, $now);

        $lines = [];
        foreach ([...$board->rooms, ...$board->received, ...$board->sent] as $challenge) {
            $other = $challenge->opponentOf($reader);
            if (null !== $other && null !== ($line = $standings->lineFor($other->getId()))) {
                $lines[(int) $other->getId()] = $line;
            }
        }

        return $this->render('quiz_tournament/challenges.html.twig', [
            'tournament' => $tournament,
            'status' => $tournament->statusAt($now),
            'board' => $board,
            'lines' => $lines,
            'now' => $now,
            'tournamentLive' => $live->screen($request, $tournament, $reader, $now),
            'activeTab' => 'challenges',
            'tournamentCrumbs' => [['label' => $this->translator->trans('quizTournamentChallengesHeading'), 'url' => $this->generateUrl('app_quiz_tournament_challenges', ['id' => $id])]],
        ]);
    }

    /** The H-defier sheet, drawn into the standings' dialog: the opponent, the enjeu, the reminders. */
    #[Route(path: '/tournaments/{id}/challenges/new/{opponent}', name: 'app_quiz_tournament_challenge_sheet', requirements: ['id' => '\d+', 'opponent' => '\d+'], methods: ['GET'])]
    public function sheet(int $id, int $opponent): Response
    {
        $tournament = $this->playableTournament($id, $this->entityManager);
        $reader = $this->player();
        $now = $this->clock->now();
        $standings = $this->assembler->assemble($tournament, $now);

        $line = $standings->lineFor($opponent);
        if (null === $line || $opponent === $reader->getId()) {
            throw $this->createNotFoundException();
        }
        $mine = $standings->lineFor($reader->getId());
        $rating = $mine?->rating() ?? QuizTournamentElo::START;
        $facts = $this->challenges->facts($tournament, $reader, $line->user, $now);

        return $this->render('quiz_tournament/_defy_sheet.html.twig', [
            'tournament' => $tournament,
            'line' => $line,
            'stakes' => QuizTournamentElo::stakes($rating, $line->rating(), $mine?->duels() ?? 0),
            'stronger' => $line->rating() > $rating,
            'refusal' => QuizTournamentChallengeRules::firstRefusal($facts, $now),
            'encountersToday' => QuizTournamentChallengeRules::encountersToday($facts->pairEncounters, $now),
            'firstName' => QuizTournamentChallenges::firstName($line->user),
        ]);
    }

    #[Route(path: '/tournaments/{id}/challenges', name: 'app_quiz_tournament_challenge_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(int $id, Request $request): Response
    {
        $tournament = $this->playableTournament($id, $this->entityManager);
        if (!$this->isCsrfTokenValid('quiz_tournament_challenge'.$id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $opponent = $this->entityManager->getRepository(User::class)->find(PostValue::int($request, 'opponent'));
        if (null === $opponent) {
            throw $this->createNotFoundException();
        }

        try {
            $this->challenges->send($tournament, $this->player(), $opponent, $this->clock->now());
        } catch (QuizTournamentRefusal $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal->messageKey, $refusal->parameters));

            return $this->redirectToRoute('app_quiz_tournament_show', ['id' => $id]);
        }

        $this->addFlash('success', $this->translator->trans('quizTournamentChallengeSentFlash', ['%name%' => QuizTournamentChallenges::firstName($opponent)]));

        return $this->redirectToRoute('app_quiz_tournament_challenges', ['id' => $id]);
    }

    #[Route(path: '/tournaments/{id}/challenges/{challenge}/accept', name: 'app_quiz_tournament_challenge_accept', requirements: ['id' => '\d+', 'challenge' => '\d+'], methods: ['POST'])]
    public function accept(int $id, int $challenge, Request $request): Response
    {
        $this->playableTournament($id, $this->entityManager);
        $row = $this->answerable($id, $challenge, $request);

        try {
            $this->challenges->accept($row, $this->player(), $this->clock->now());
        } catch (QuizTournamentRefusal $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal->messageKey, $refusal->parameters));

            return $this->redirectToRoute('app_quiz_tournament_challenges', ['id' => $id]);
        }

        return $this->redirectToRoute('app_quiz_tournament_room', ['id' => $id, 'challenge' => $challenge]);
    }

    #[Route(path: '/tournaments/{id}/challenges/{challenge}/refuse', name: 'app_quiz_tournament_challenge_refuse', requirements: ['id' => '\d+', 'challenge' => '\d+'], methods: ['POST'])]
    public function refuse(int $id, int $challenge, Request $request): Response
    {
        $this->viewableTournament($id, $this->entityManager);
        $row = $this->answerable($id, $challenge, $request);

        try {
            $this->challenges->refuse($row, $this->player(), $this->clock->now());
        } catch (QuizTournamentRefusal $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal->messageKey, $refusal->parameters));
        }

        return $this->redirectToRoute('app_quiz_tournament_challenges', ['id' => $id]);
    }

    private function answerable(int $tournamentId, int $challengeId, Request $request): QuizTournamentChallenge
    {
        if (!$this->isCsrfTokenValid('quiz_tournament_answer'.$challengeId, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $tournament = $this->viewableTournament($tournamentId, $this->entityManager);
        $row = $this->ownChallenge($tournament, $challengeId, $this->entityManager);
        if ($row->getChallenged() !== $this->player()) {
            throw $this->createNotFoundException();
        }

        return $row;
    }
}
