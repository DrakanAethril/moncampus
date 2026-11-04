<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizQuestion;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentDuelQuestion;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Enum\QuizTournamentDuelOrigin;
use App\Repository\QuizTournamentDuelRepository;
use App\Repository\QuizTournamentPlayerRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The making of a duel (design/validated/quiz-tournament.md §5.1), whatever it is born of - a
 * challenge whose two players are ready (QuizTournamentDuelStarter), or a pair of a round in class
 * (QuizTournamentRounds). The caller holds the locks and the transaction, and flushes.
 *
 * Both players' lines are created if missing; the ratings before; the ladder flags of both on the
 * standings of this moment (§6.2); a seed; the questions drawn by QuizTournamentQuestionDraw over
 * the tournament's sources, leaving out what either player saw in their last three duels; each
 * question **frozen** into a QuizTournamentDuelQuestion (QuizQuestionDefinitionTrait, its source
 * quiz and question, its choices in the order both see - QuizTournamentDuelSnapshot).
 *
 * A question's image is referenced, not copied: a duel is played within minutes, and copying
 * twenty images per duel into the bucket for ever would cost more than a correction that loses a
 * picture deleted from the library.
 */
class QuizTournamentDuelFactory
{
    public function __construct(
        private readonly QuizTournamentPlayerRepository $players,
        private readonly QuizTournamentDuelRepository $duels,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * A duel between $a (player A) and $b, persisted with its frozen questions - not flushed.
     *
     * @param list<array{id: int, rating: int}> $rankedRatings   the ranked players now (QuizTournamentStandings::rankedRatings())
     * @param array<int, QuizQuestion>|null $sourceQuestions the tournament's sources, when the caller makes several duels at once
     */
    public function create(
        QuizTournament $tournament,
        User $a,
        User $b,
        QuizTournamentDuelOrigin $origin,
        \DateTimeImmutable $startedAt,
        array $rankedRatings,
        ?array $sourceQuestions = null,
    ): QuizTournamentDuel {
        $playerA = $this->players->findOrCreate($tournament, $a);
        $playerB = $this->players->findOrCreate($tournament, $b);
        $seed = QuizTournamentDuelSnapshot::newSeed();

        $duel = new QuizTournamentDuel(
            $tournament,
            $a,
            $b,
            $origin,
            $seed,
            $startedAt,
            $playerA->getRating(),
            $playerB->getRating(),
            QuizTournamentStanding::isLadderOpponent((int) $a->getId(), $playerA->getRating(), (int) $b->getId(), $rankedRatings),
            QuizTournamentStanding::isLadderOpponent((int) $b->getId(), $playerB->getRating(), (int) $a->getId(), $rankedRatings),
        );
        $this->entityManager->persist($duel);

        $questions = $sourceQuestions ?? $this->sourceQuestions($tournament);
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

        return $duel;
    }

    /**
     * Every question of the tournament's sources, answers loaded, keyed by id.
     *
     * @return array<int, QuizQuestion>
     */
    public function sourceQuestions(QuizTournament $tournament): array
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
}
