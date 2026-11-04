<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Attribute\RequiresFeature;
use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentRound;
use App\Enum\Feature;
use App\Repository\QuizTournamentRoundRepository;
use App\Security\Voter\QuizTournamentVoter;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\QuizTournament\QuizTournamentAccess;
use App\Service\QuizTournament\QuizTournamentClass;
use App\Service\QuizTournament\QuizTournamentLive;
use App\Service\QuizTournament\QuizTournamentRefusal;
use App\Service\QuizTournament\QuizTournamentRoundBoard;
use App\Service\QuizTournament\QuizTournamentRounds;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rounds in class (design/validated/quiz-tournament.md §8 and §10, mockup H-ronde).
 *
 * The teacher's side: « Lancer une ronde » (the class among the targeted ones they teach, then the
 * restriction - none, an Option of the class, a group of a lot of theirs or shared with them), in
 * Formatool's own design; then the **projected screen**, outside the app shell and in the
 * tournament's dress, 16:9, readable from the back of the room - registrations (who is present on
 * whom), « Clore et apparier », « Annuler la ronde », then the duels with their live scores. Only
 * the teacher who opened a round reaches it (404 for anybody else); opening and pairing are
 * QuizTournamentVoter::OPEN_ROUND for that class.
 *
 * The student's side: « Rejoindre la ronde » / « Quitter la ronde » from a tournament screen, the
 * beat of the page while they wait, and the card itself, asked again when the round changes.
 */
#[RequiresFeature(Feature::QuizTournaments)]
class RoundController extends AbstractController
{
    use QuizTournamentControllerTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly QuizTournamentRounds $rounds,
        private readonly QuizTournamentRoundRepository $roundRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/tournaments/{id}/rounds/new', name: 'app_quiz_tournament_round_new', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function new(int $id, Request $request, QuizTournamentAccess $access): Response
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        if (!$this->isGranted(QuizTournamentVoter::OPEN_ROUND, $tournament)) {
            throw $this->createNotFoundException();
        }
        $teacher = $this->player();
        $programs = $this->taughtPrograms($tournament, $access);
        if ([] === $programs) {
            throw $this->createNotFoundException();
        }

        $program = $programs[QueryValue::int($request, 'program')] ?? reset($programs);
        $options = $program->getOptions()->toArray();
        usort($options, static fn (Option $a, Option $b): int => strcasecmp($a->getName(), $b->getName()));

        return $this->render('quiz_tournament/round_new.html.twig', [
            'tournament' => $tournament,
            'programs' => $programs,
            'program' => $program,
            'options' => $options,
            'batches' => $this->rounds->readableBatches($teacher, $program),
            'openRound' => $this->roundRepository->findOpenForProgram($tournament, $program),
        ]);
    }

    #[Route(path: '/tournaments/{id}/rounds', name: 'app_quiz_tournament_round_open', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function open(int $id, Request $request, QuizTournamentAccess $access): Response
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        if (!$this->isCsrfTokenValid('quiz_tournament_round_open'.$id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $programs = $this->taughtPrograms($tournament, $access);
        $program = $programs[PostValue::int($request, 'program')] ?? null;
        if (null === $program) {
            throw $this->createNotFoundException();
        }

        // « Qui joue ? »: `class`, `option:<id>` or `group:<lot id>:<group index>`. A forged
        // restriction is loaded as asked and refused by the rules (QuizTournamentRoundRules).
        $who = explode(':', PostValue::string($request, 'who'));
        $ids = array_map(intval(...), \array_slice($who, 1));
        $option = null;
        $batch = null;
        $groupIndex = null;
        if ('option' === $who[0] && 1 === \count($ids)) {
            $option = $this->entityManager->getRepository(Option::class)->find($ids[0]);
            if (null === $option) {
                return $this->refuse('quizTournamentRoundRefusalPickWho', $id, $program);
            }
        } elseif ('group' === $who[0] && 2 === \count($ids)) {
            $batch = $this->entityManager->getRepository(GroupBatch::class)->find($ids[0]);
            if (null === $batch) {
                return $this->refuse('quizTournamentRoundRefusalPickWho', $id, $program);
            }
            $groupIndex = $ids[1];
        } elseif ('class' !== $who[0]) {
            return $this->refuse('quizTournamentRoundRefusalPickWho', $id, $program);
        }

        try {
            $round = $this->rounds->open(
                $tournament,
                $program,
                $this->player(),
                $this->isGranted(QuizTournamentVoter::OPEN_ROUND, new QuizTournamentClass($tournament, $program)),
                $option,
                $batch,
                $groupIndex,
                $this->clock->now(),
            );
        } catch (QuizTournamentRefusal $refusal) {
            return $this->refuse($refusal->messageKey, $id, $program, $refusal->parameters);
        }

        return $this->redirectToRoute('app_quiz_tournament_round', ['id' => $id, 'round' => $round->getId()]);
    }

    /** The projected screen: registrations, then the duels. */
    #[Route(path: '/tournaments/{id}/rounds/{round}', name: 'app_quiz_tournament_round', requirements: ['id' => '\d+', 'round' => '\d+'], methods: ['GET'])]
    public function show(int $id, int $round, Request $request, QuizTournamentRoundBoard $board, QuizTournamentLive $live): Response
    {
        $theRound = $this->ownRound($id, $round);
        $topic = $live->roundTopic($theRound);

        return $this->render('quiz_tournament/round.html.twig', [
            'tournament' => $theRound->getTournament(),
            'round' => $theRound,
            'board' => $board->read($theRound, $this->player(), $this->clock->now()),
            'hubUrl' => $live->subscribe($request, [$topic]) ? $live->publicUrl() : '',
            'topic' => $topic,
        ]);
    }

    /** The projected screen's stage, drawn again when the round moves (a join, a duel's end, a refresh). */
    #[Route(path: '/tournaments/{id}/rounds/{round}/board', name: 'app_quiz_tournament_round_board', requirements: ['id' => '\d+', 'round' => '\d+'], methods: ['GET'])]
    public function board(int $id, int $round, QuizTournamentRoundBoard $board): Response
    {
        $theRound = $this->ownRound($id, $round);

        return $this->render('quiz_tournament/_round_board.html.twig', [
            'tournament' => $theRound->getTournament(),
            'round' => $theRound,
            'board' => $board->read($theRound, $this->player(), $this->clock->now()),
        ]);
    }

    /** « Clore et apparier ». */
    #[Route(path: '/tournaments/{id}/rounds/{round}/close', name: 'app_quiz_tournament_round_close', requirements: ['id' => '\d+', 'round' => '\d+'], methods: ['POST'])]
    public function close(int $id, int $round, Request $request): Response
    {
        $theRound = $this->ownRound($id, $round);
        $this->checkToken('quiz_tournament_round'.$round, PostValue::string($request, '_token'));

        try {
            $this->rounds->closeAndPair(
                $theRound,
                $this->isGranted(QuizTournamentVoter::OPEN_ROUND, new QuizTournamentClass($theRound->getTournament(), $theRound->getProgram())),
                $this->clock->now(),
            );
        } catch (QuizTournamentRefusal $refusal) {
            $this->addFlash('danger', $this->translator->trans($refusal->messageKey, $refusal->parameters));
        }

        return $this->redirectToRoute('app_quiz_tournament_round', ['id' => $id, 'round' => $round]);
    }

    /** « Annuler la ronde » - a round not paired yet. */
    #[Route(path: '/tournaments/{id}/rounds/{round}/cancel', name: 'app_quiz_tournament_round_cancel', requirements: ['id' => '\d+', 'round' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, int $round, Request $request): Response
    {
        $theRound = $this->ownRound($id, $round);
        $this->checkToken('quiz_tournament_round'.$round, PostValue::string($request, '_token'));
        $this->rounds->cancel($theRound, $this->clock->now());
        $this->addFlash('success', $this->translator->trans('quizTournamentRoundCancelledFlash'));

        return $this->redirectToRoute('app_quiz_tournament_show', ['id' => $id]);
    }

    /** « Rejoindre la ronde », from a tournament screen of the student's. */
    #[Route(path: '/tournaments/{id}/rounds/{round}/join', name: 'app_quiz_tournament_round_join', requirements: ['id' => '\d+', 'round' => '\d+'], methods: ['POST'])]
    public function join(int $id, int $round, Request $request): Response
    {
        $theRound = $this->studentRound($id, $round, PostValue::string($request, '_token'));
        try {
            $this->rounds->join($theRound, $this->player(), $this->clock->now());
        } catch (QuizTournamentRefusal $refusal) {
            $this->addFlash('danger', $this->translator->trans($refusal->messageKey, $refusal->parameters));
        }

        return $this->back($id, PostValue::string($request, 'back'));
    }

    /** « Quitter la ronde ». */
    #[Route(path: '/tournaments/{id}/rounds/{round}/leave', name: 'app_quiz_tournament_round_leave', requirements: ['id' => '\d+', 'round' => '\d+'], methods: ['POST'])]
    public function leave(int $id, int $round, Request $request): Response
    {
        $theRound = $this->studentRound($id, $round, PostValue::string($request, '_token'));
        $this->rounds->leave($theRound, $this->player());

        return $this->back($id, PostValue::string($request, 'back'));
    }

    /** The page of a student who joined, every few seconds: still there; where do I stand? */
    #[Route(path: '/tournaments/{id}/rounds/{round}/beat', name: 'app_quiz_tournament_round_beat', requirements: ['id' => '\d+', 'round' => '\d+'], methods: ['POST'])]
    public function beat(int $id, int $round, Request $request): JsonResponse
    {
        $theRound = $this->studentRound($id, $round, $request->headers->get('X-CSRF-Token'));

        return $this->json($this->rounds->beat($theRound, $this->player(), $this->clock->now()));
    }

    /** The round's card of a student's tournament screen, asked again when a round changes. */
    #[Route(path: '/tournaments/{id}/round-entry', name: 'app_quiz_tournament_round_entry', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entry(int $id, Request $request): Response
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        if (!$this->isGranted('ROLE_STUDENT')) {
            throw $this->createNotFoundException();
        }

        return $this->render('quiz_tournament/_round_entry_card.html.twig', [
            'tournament' => $tournament,
            'entry' => $this->rounds->entryFor($tournament, $this->player(), $this->clock->now()),
            'back' => 'challenges' === QueryValue::string($request, 'back') ? 'challenges' : 'standings',
        ]);
    }

    /**
     * The targeted classes this teacher teaches, keyed by id, in the targeting's order.
     *
     * @return array<int, Program>
     */
    private function taughtPrograms(QuizTournament $tournament, QuizTournamentAccess $access): array
    {
        $programs = [];
        foreach ($access->taughtTargetedProgramIds($this->player(), $tournament) as $programId) {
            $program = $this->entityManager->getRepository(Program::class)->find($programId);
            if (null !== $program) {
                $programs[$programId] = $program;
            }
        }

        return $programs;
    }

    /** A round of this tournament the current person opened. */
    private function ownRound(int $id, int $roundId): QuizTournamentRound
    {
        $tournament = $this->viewableTournament($id, $this->entityManager);
        $round = $this->roundRepository->find($roundId);
        if (null === $round || $round->getTournament() !== $tournament || $round->getTeacher() !== $this->player()) {
            throw $this->createNotFoundException();
        }

        return $round;
    }

    /** A round of this tournament, for a student's gesture. */
    private function studentRound(int $id, int $roundId, ?string $token): QuizTournamentRound
    {
        $this->checkToken('quiz_tournament_round_entry'.$roundId, $token);
        $tournament = $this->viewableTournament($id, $this->entityManager);
        $round = $this->roundRepository->find($roundId);
        if (null === $round || $round->getTournament() !== $tournament || !$this->isGranted('ROLE_STUDENT')) {
            throw $this->createNotFoundException();
        }

        return $round;
    }

    private function checkToken(string $id, ?string $token): void
    {
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function back(int $id, string $back): Response
    {
        return $this->redirectToRoute('challenges' === $back ? 'app_quiz_tournament_challenges' : 'app_quiz_tournament_show', ['id' => $id]);
    }

    /** @param array<string, string|int> $parameters */
    private function refuse(string $messageKey, int $id, Program $program, array $parameters = []): Response
    {
        $this->addFlash('danger', $this->translator->trans($messageKey, $parameters));

        return $this->redirectToRoute('app_quiz_tournament_round_new', ['id' => $id, 'program' => $program->getId()]);
    }
}
