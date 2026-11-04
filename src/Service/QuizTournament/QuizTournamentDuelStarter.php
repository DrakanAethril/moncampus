<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizQuestion;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelQuestion;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentDuelOrigin;
use App\Repository\QuizTournamentDuelRepository;
use App\Repository\QuizTournamentPlayerRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The start of a duel born of a challenge (design/validated/quiz-tournament.md §4.3 and §5.1), once
 * both players are ready in the room. Called by every « Prêt » and every heartbeat: whichever
 * request sees both ready first starts it, and **only one** does.
 *
 * Under a lock on the room (LOCK_DSN, shared by the containers) and inside one transaction, the
 * challenge row read again FOR UPDATE:
 * 1. a challenge that already has its duel answers that duel - the other player's request, a
 *    second tab, a retry;
 * 2. QuizTournamentRoomRules::shouldStart() on facts read now - both still ready (a heartbeat under
 *    45 s), both still eligible, the room and the tournament open, neither playing elsewhere;
 * 3. the duel: player A the challenger; both lines created if missing; ratings before; the ladder
 *    flags of both on the standings of this moment (§6.2); a seed; the questions drawn by
 *    QuizTournamentQuestionDraw over the tournament's sources, leaving out what either player saw
 *    in their last three duels; each question **frozen** into a QuizTournamentDuelQuestion
 *    (QuizQuestionDefinitionTrait, its source quiz and question, its choices in the order both
 *    see - QuizTournamentDuelSnapshot); the challenge marked played.
 *
 * Then « start » is published on the room's topic and both counters move. The questions are not
 * served yet: the duel screen serves each one (QuizTournamentDuelQuestion::serve()).
 *
 * A question's image is referenced, not copied: a duel is played within minutes, and copying
 * twenty images per duel into the bucket for ever would cost more than a correction that loses a
 * picture deleted from the library.
 */
class QuizTournamentDuelStarter
{
    private const int LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly QuizTournamentAccess $access,
        private readonly QuizTournamentStandingsAssembler $standings,
        private readonly QuizTournamentPlayerRepository $players,
        private readonly QuizTournamentDuelRepository $duels,
        private readonly QuizTournamentLive $live,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /** The duel of this room - started now if both are ready, already started, or null (not yet). */
    public function startIfReady(QuizTournamentChallenge $challenge, \DateTimeImmutable $now): ?QuizTournamentDuel
    {
        $lock = $this->lockFactory->createLock(\sprintf('quiz-tournament-room-%d', (int) $challenge->getId()), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            /** @var array{0: QuizTournamentDuel|null, 1: bool} $outcome */
            $outcome = $this->entityManager->wrapInTransaction(function () use ($challenge, $now): array {
                $this->entityManager->refresh($challenge, LockMode::PESSIMISTIC_WRITE);
                $existing = $challenge->getDuel();
                if (null !== $existing) {
                    return [$existing, false];
                }

                if (!QuizTournamentRoomRules::shouldStart($this->access->roomFacts($challenge, $challenge->getChallenger(), $now))) {
                    return [null, false];
                }

                return [$this->create($challenge, $now), true];
            });
        } finally {
            $lock->release();
        }

        [$duel, $created] = $outcome;
        if (null !== $duel && $created) {
            $this->live->publishStart($challenge, $duel);
            $this->live->publishCounters($challenge->getTournament(), $now, $challenge->getChallenger(), $challenge->getChallenged());
        }

        return $duel;
    }

    private function create(QuizTournamentChallenge $challenge, \DateTimeImmutable $now): QuizTournamentDuel
    {
        $tournament = $challenge->getTournament();
        $a = $challenge->getChallenger();
        $b = $challenge->getChallenged();
        $playerA = $this->players->findOrCreate($tournament, $a);
        $playerB = $this->players->findOrCreate($tournament, $b);

        $ranked = $this->standings->standings($tournament, $now)->rankedRatings();
        $seed = QuizTournamentDuelSnapshot::newSeed();

        $duel = new QuizTournamentDuel(
            $tournament,
            $a,
            $b,
            QuizTournamentDuelOrigin::Challenge,
            $seed,
            $now,
            $playerA->getRating(),
            $playerB->getRating(),
            QuizTournamentStanding::isLadderOpponent((int) $a->getId(), $playerA->getRating(), (int) $b->getId(), $ranked),
            QuizTournamentStanding::isLadderOpponent((int) $b->getId(), $playerB->getRating(), (int) $a->getId(), $ranked),
        );
        $this->entityManager->persist($duel);

        $questions = $this->sourceQuestions($tournament);
        $drawn = QuizTournamentQuestionDraw::draw(
            QuizTournamentDuelSnapshot::candidates(array_values(array_map(static fn (QuizQuestion $question): array => [
                'questionId' => (int) $question->getId(),
                'quizId' => (int) $question->getQuizTemplate()?->getId(),
                'type' => $question->getType(),
            ], $questions))),
            array_values(array_unique([
                ...$this->duels->recentlySeenQuestionIds($tournament, $a),
                ...$this->duels->recentlySeenQuestionIds($tournament, $b),
            ])),
            $tournament->getQuestionsPerDuel(),
            $tournament->getMaxTrueFalsePerDuel(),
            $seed,
        );

        foreach ($drawn as $rank => $questionId) {
            $this->entityManager->persist($this->freeze($duel, $rank, $questions[$questionId], $seed));
        }

        $challenge->markPlayed($duel);
        $this->entityManager->flush();

        return $duel;
    }

    private function freeze(QuizTournamentDuel $duel, int $rank, QuizQuestion $question, int $seed): QuizTournamentDuelQuestion
    {
        $type = $question->getType();
        $frozen = new QuizTournamentDuelQuestion($duel, $rank, $type, $duel->getTournament()->secondsFor($type));
        $frozen->setLabel($question->getLabel());
        $frozen->setImageStorageKey($question->getImageStorageKey());
        $frozen->setNumericConfig($question->getNumericConfig());
        $frozen->setPoints($question->getPoints());
        $frozen->setExplanation($question->getExplanation());

        $quiz = $question->getQuizTemplate();
        if (null !== $quiz) {
            $frozen->setSource($quiz, $question);
        }

        $answers = [];
        if (QuestionType::Numerique !== $type) {
            foreach ($question->getAnswers() as $answer) {
                $answers[] = ['label' => (string) $answer->getLabel(), 'correct' => $answer->isCorrect()];
            }
        }
        $frozen->setChoices(QuizTournamentDuelSnapshot::choices($type, $answers, $seed, $rank));

        return $frozen;
    }

    /**
     * Every question of the tournament's sources, answers loaded, keyed by id.
     *
     * @return array<int, QuizQuestion>
     */
    private function sourceQuestions(QuizTournament $tournament): array
    {
        if ($tournament->getSources()->isEmpty()) {
            return [];
        }

        /** @var list<QuizQuestion> $questions */
        $questions = $this->entityManager->createQueryBuilder()
            ->select('q', 'a', 'quiz')
            ->from(QuizQuestion::class, 'q')
            ->innerJoin('q.quizTemplate', 'quiz')
            ->leftJoin('q.answers', 'a')
            ->where('q.quizTemplate IN (:sources)')
            ->setParameter('sources', $tournament->getSources()->toArray())
            ->orderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($questions as $question) {
            $byId[(int) $question->getId()] = $question;
        }

        return $byId;
    }
}
