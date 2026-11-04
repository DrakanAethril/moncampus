<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelAnswer;
use App\Entity\QuizTournamentDuelQuestion;
use App\Entity\User;
use App\Enum\QuizTournamentDuelPhase;
use App\Repository\QuizTournamentDuelAnswerRepository;
use App\Repository\QuizTournamentPlayerRepository;
use App\Service\FileUploadService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The duel as it is played (design/validated/quiz-tournament.md §5.2 to §6.1): the one door through
 * which a duel moves - the screen's state, an answer, the scheduled task.
 *
 * **Server-timed and lazy.** QuizTournamentDuelClock says from the facts when each question is served
 * and closes; nothing runs between requests. Every request - the duel screen asking for its state at
 * the moment its clock says something changes, an answer, app:quiz-tournament:close-duels each minute
 * - first brings the duel up to now ({@see advance()}): it stamps the serves whose time has come (at
 * the moment computed, never at the time of the request, and only once - a reload restarts no clock),
 * and finishes a duel whose last question is over. Under a lock per duel (LOCK_DSN, shared with the
 * worker), so two requests never both serve, both accept one player's answer, or both finish.
 *
 * **What a player learns.** The public state ({@see publicState()}) is the same for both players and
 * is what goes over Mercure (QuizTournamentLive::duelTopic()): the question in play without its
 * correction, who has answered it, right or wrong for each once it closed, the scores. **Never the
 * right answer, never what the other picked**; a player's own answer to the question in play comes
 * back only in the answer to their own request (`mine`).
 *
 * **The end** ({@see finish()}), exactly once: one transaction, the duel row FOR UPDATE then both
 * players locked in id order (QuizTournamentPlayerRepository::lockPlayers()); a duel already
 * finished is left as it is. The score is the sum of the points (QuizTournamentDuelGrader, 0 or 1 a
 * question, an unanswered question 0); QuizTournamentElo::rate() with each player's K; both lines
 * move (QuizTournamentPlayer::recordDuel()) and the duel keeps both ratings after.
 */
class QuizTournamentDuelEngine
{
    private const int LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly QuizTournamentDuelAnswerRepository $answers,
        private readonly QuizTournamentPlayerRepository $players,
        private readonly QuizTournamentDuelGrader $grader,
        private readonly QuizTournamentLive $live,
        private readonly FileUploadService $files,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * The duel brought up to now, as this player sees it: the public state, plus their own answer to
     * the question in play.
     *
     * @return array<string, mixed>
     */
    public function state(QuizTournamentDuel $duel, User $viewer, \DateTimeImmutable $now): array
    {
        $now = QuizTournamentDuelClock::second($now);
        [$timeline, $answers, $moved] = $this->locked($duel, fn (): array => $this->advance($duel, $now));

        $public = $this->publicState($duel, $timeline, $answers, $now);
        if ($moved) {
            $this->live->publishDuel($duel, $public);
        }

        return [...$public, 'mine' => $this->mine($timeline, $answers, $viewer, $now)];
    }

    /**
     * One player's answer to the question in play. Refused - QuizTournamentRefusal - when the duel is
     * over, the question is not the one served or its deadline passed, the player already answered
     * it, or the answer does not fit the question. The answer is graded now and kept; a question both
     * players answered closes at once.
     *
     * @param list<int> $selected choice ids (QuizTournamentDuelQuestion::$choices)
     *
     * @return array<string, mixed> the state, as state() answers it
     */
    public function answer(QuizTournamentDuel $duel, User $player, int $rank, array $selected, ?float $value, ?string $unit, \DateTimeImmutable $now): array
    {
        $now = QuizTournamentDuelClock::second($now);
        if (!$duel->involves($player)) {
            throw new QuizTournamentRefusal('quizTournamentDuelRefusalNotYours');
        }

        [$timeline, $answers] = $this->locked($duel, function () use ($duel, $player, $rank, $selected, $value, $unit, $now): array {
            [$timeline, $answers] = $this->advance($duel, $now);
            if ($duel->isFinished()) {
                throw new QuizTournamentRefusal('quizTournamentDuelRefusalOver');
            }

            $question = $this->questionsByRank($duel)[$rank] ?? null;
            if (null === $question || !$timeline->acceptsAnswer($rank, $now)) {
                throw new QuizTournamentRefusal('quizTournamentDuelRefusalTooLate');
            }
            $playerId = (int) $player->getId();
            if (isset($answers[$rank][$playerId])) {
                throw new QuizTournamentRefusal('quizTournamentDuelRefusalAlreadyAnswered');
            }
            $choiceIds = array_map(static fn (array $choice): int => $choice['id'], $question->getChoices());
            if (!QuizTournamentDuelGrader::isWellFormed($question->getType(), $choiceIds, $selected, $value)) {
                throw new QuizTournamentRefusal('quizTournamentDuelRefusalMalformed');
            }

            $answer = new QuizTournamentDuelAnswer($question, $player, $selected, $value, $unit, $this->grader->points($question, $selected, $value, $unit), $now);
            $this->entityManager->persist($answer);
            $this->entityManager->flush();
            $answers[$rank][$playerId] = $answer;

            return [$this->timelineOf($duel, $answers), $answers];
        });

        $public = $this->publicState($duel, $timeline, $answers, $now);
        $this->live->publishDuel($duel, $public);

        return [...$public, 'mine' => $this->mine($timeline, $answers, $player, $now)];
    }

    /**
     * What app:quiz-tournament:close-duels does to one unfinished duel: brings it up to now, which
     * finishes it if its last question is over. True when it finished it.
     */
    public function settle(QuizTournamentDuel $duel, \DateTimeImmutable $now): bool
    {
        $now = QuizTournamentDuelClock::second($now);
        $wasFinished = $duel->isFinished();
        [$timeline, $answers, $moved] = $this->locked($duel, fn (): array => $this->advance($duel, $now));
        if ($moved) {
            $this->live->publishDuel($duel, $this->publicState($duel, $timeline, $answers, $now));
        }

        return !$wasFinished && $duel->isFinished();
    }

    /**
     * The duel's clock from what is stored now.
     *
     * @param array<int, array<int, QuizTournamentDuelAnswer>> $answers rank => player id => answer
     */
    public function timelineOf(QuizTournamentDuel $duel, array $answers): QuizTournamentDuelTimeline
    {
        $questions = [];
        foreach ($this->questionsByRank($duel) as $rank => $question) {
            $questions[] = [
                'seconds' => $question->getTimeLimitSeconds(),
                'servedAt' => $question->getServedAt(),
                'answeredAt' => array_values(array_map(static fn (QuizTournamentDuelAnswer $answer): \DateTimeImmutable => $answer->getAnsweredAt(), $answers[$rank] ?? [])),
            ];
        }

        return QuizTournamentDuelClock::timeline($duel->getStartedAt(), $questions);
    }

    /**
     * The duel's answers, rank => player id => answer.
     *
     * @return array<int, array<int, QuizTournamentDuelAnswer>>
     */
    public function answersByRank(QuizTournamentDuel $duel): array
    {
        $byRank = [];
        foreach ($this->answers->findForDuel($duel) as $answer) {
            $byRank[$answer->getQuestion()->getRank()][(int) $answer->getPlayer()->getId()] = $answer;
        }

        return $byRank;
    }

    /**
     * Stamps the serves due by now and finishes a duel that is over. Runs under the duel's lock.
     *
     * @return array{0: QuizTournamentDuelTimeline, 1: array<int, array<int, QuizTournamentDuelAnswer>>, 2: bool} the timeline, the answers, and whether anything moved
     */
    private function advance(QuizTournamentDuel $duel, \DateTimeImmutable $now): array
    {
        $answers = $this->answersByRank($duel);
        $timeline = $this->timelineOf($duel, $answers);
        if ($duel->isFinished()) {
            return [$timeline, $answers, false];
        }

        $moved = false;
        $questions = $this->questionsByRank($duel);
        foreach ($timeline->dueToServe($now) as $rank => $servedAt) {
            $questions[$rank]->serve($servedAt);
            $moved = true;
        }
        if ($moved) {
            $this->entityManager->flush();
            $timeline = $this->timelineOf($duel, $answers);
        }

        if ($timeline->isOver($now) && $this->finish($duel, $timeline, $answers)) {
            $moved = true;
        }

        return [$timeline, $answers, $moved];
    }

    /**
     * The end of the duel (§5.3, §6.1), exactly once.
     *
     * @param array<int, array<int, QuizTournamentDuelAnswer>> $answers
     */
    private function finish(QuizTournamentDuel $duel, QuizTournamentDuelTimeline $timeline, array $answers): bool
    {
        /** @var bool $finished */
        $finished = $this->entityManager->wrapInTransaction(function () use ($duel, $timeline, $answers): bool {
            $this->entityManager->refresh($duel, LockMode::PESSIMISTIC_WRITE);
            if ($duel->isFinished()) {
                return false;
            }

            $tournament = $duel->getTournament();
            $lineA = $this->players->findOrCreate($tournament, $duel->getPlayerA());
            $lineB = $this->players->findOrCreate($tournament, $duel->getPlayerB());
            if (null === $lineA->getId() || null === $lineB->getId()) {
                $this->entityManager->flush();
            }
            $this->players->lockPlayers($lineA, $lineB);

            $idA = (int) $duel->getPlayerA()->getId();
            $idB = (int) $duel->getPlayerB()->getId();
            $scoreA = QuizTournamentDuelReading::score(array_values(array_map(static fn (array $byPlayer): ?float => ($byPlayer[$idA] ?? null)?->getPoints(), $answers)));
            $scoreB = QuizTournamentDuelReading::score(array_values(array_map(static fn (array $byPlayer): ?float => ($byPlayer[$idB] ?? null)?->getPoints(), $answers)));

            [$ratingA, $ratingB] = QuizTournamentElo::rate($lineA->getRating(), $lineB->getRating(), $scoreA, $scoreB, $lineA->getDuels(), $lineB->getDuels());
            $lineA->recordDuel($ratingA, QuizTournamentElo::outcome($scoreA, $scoreB));
            $lineB->recordDuel($ratingB, QuizTournamentElo::outcome($scoreB, $scoreA));
            $duel->finish($timeline->endsAt, $scoreA, $scoreB, $ratingA, $ratingB);
            $this->entityManager->flush();

            return true;
        });

        return $finished;
    }

    /**
     * The state both players read, and the duel's topic carries.
     *
     * @param array<int, array<int, QuizTournamentDuelAnswer>> $answers
     *
     * @return array<string, mixed>
     */
    private function publicState(QuizTournamentDuel $duel, QuizTournamentDuelTimeline $timeline, array $answers, \DateTimeImmutable $now): array
    {
        $ids = [(int) $duel->getPlayerA()->getId(), (int) $duel->getPlayerB()->getId()];
        $phase = $duel->isFinished() ? QuizTournamentDuelPhase::Finished : $timeline->phaseAt($now);
        $rank = QuizTournamentDuelPhase::Finished === $phase ? null : $timeline->rankAt($now);
        $questions = $this->questionsByRank($duel);

        $results = [];
        $scores = array_fill_keys($ids, 0.0);
        foreach ($timeline->moments as $moment) {
            if (!$timeline->isClosed($moment->rank, $now) && QuizTournamentDuelPhase::Finished !== $phase) {
                $results[] = null;
                continue;
            }
            $row = [];
            foreach ($ids as $id) {
                $points = ($answers[$moment->rank][$id] ?? null)?->getPoints();
                $row[$id] = $points;
                $scores[$id] += $points ?? 0.0;
            }
            $results[] = $row;
        }

        $answered = [];
        foreach ($ids as $id) {
            $answered[$id] = null !== $rank && isset($answers[$rank][$id]);
        }

        $question = null;
        if (null !== $rank && isset($questions[$rank])) {
            $question = $this->questionView($questions[$rank], $timeline->moments[$rank]);
        }
        $next = $timeline->nextChangeAt($now);

        return [
            'serverTime' => $now->format(\DATE_ATOM),
            'phase' => $phase->value,
            'total' => \count($timeline->moments),
            'rank' => $rank,
            'nextChangeAt' => QuizTournamentDuelPhase::Finished === $phase ? null : $next?->format(\DATE_ATOM),
            'question' => $question,
            'answered' => $answered,
            'results' => $results,
            'scores' => array_map(static fn (float $score): float => round($score, 2), $scores),
            'resultUrl' => QuizTournamentDuelPhase::Finished === $phase
                ? $this->urls->generate('app_quiz_tournament_duel_result', ['id' => $duel->getTournament()->getId(), 'duel' => $duel->getId()])
                : null,
        ];
    }

    /**
     * A question as the duel screen draws it: never its correction.
     *
     * @return array<string, mixed>
     */
    private function questionView(QuizTournamentDuelQuestion $question, QuizTournamentDuelMoment $moment): array
    {
        $image = $question->getImageStorageKey();

        return [
            'rank' => $question->getRank(),
            'type' => $question->getType()->value,
            'label' => (string) $question->getLabel(),
            'image' => null !== $image ? $this->files->url($image) : null,
            'choices' => array_map(static fn (array $choice): array => ['id' => $choice['id'], 'label' => $choice['label']], $question->getChoices()),
            'unit' => $question->getNumericUnit(),
            'unitRequired' => $question->isNumericUnitRequired(),
            'seconds' => $question->getTimeLimitSeconds(),
            'servedAt' => $moment->servedAt->format(\DATE_ATOM),
            'deadlineAt' => $moment->deadlineAt->format(\DATE_ATOM),
        ];
    }

    /**
     * The viewer's own answer to the question in play - only ever in the answer to their request.
     *
     * @param array<int, array<int, QuizTournamentDuelAnswer>> $answers
     *
     * @return array{choices: list<int>, value: float|null, unit: string|null}|null
     */
    private function mine(QuizTournamentDuelTimeline $timeline, array $answers, User $viewer, \DateTimeImmutable $now): ?array
    {
        $rank = $timeline->rankAt($now);
        $answer = null === $rank ? null : ($answers[$rank][(int) $viewer->getId()] ?? null);
        if (null === $answer) {
            return null;
        }

        return ['choices' => $answer->getSelectedChoiceIds(), 'value' => $answer->getNumericValue(), 'unit' => $answer->getNumericUnit()];
    }

    /** @return array<int, QuizTournamentDuelQuestion> */
    private function questionsByRank(QuizTournamentDuel $duel): array
    {
        $byRank = [];
        foreach ($duel->getQuestions() as $question) {
            $byRank[$question->getRank()] = $question;
        }
        ksort($byRank);

        return $byRank;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(QuizTournamentDuel $duel, callable $work): mixed
    {
        $lock = $this->lockFactory->createLock(\sprintf('quiz-tournament-duel-%d', (int) $duel->getId()), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
