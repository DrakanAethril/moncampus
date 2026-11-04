<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\User;
use App\Enum\QuestionType;
use App\Repository\QuizTournamentDuelAnswerRepository;
use App\Repository\QuizTournamentDuelRepository;
use App\Service\FileUploadService;
use App\Service\QuizAnswerChecker;
use App\Service\UserAvatars;

/**
 * What the screens after a duel read (design/validated/quiz-tournament.md §5.4 and §10): the result
 * or the defeat (H-resultat, H-defaite), the correction (H-correction) and a player's history
 * (H-mes-duels). Always from one player's side - « toi » and the other - and only of a finished
 * duel: the controller refuses an unfinished one before asking.
 *
 * The rules are QuizTournamentDuelReading's; this loads the facts once per screen and lays them out
 * as plain arrays for the templates.
 *
 * @phpstan-type Side array{user: User, score: float, before: int, after: int, delta: int, avatar: array{photo: string|null, drawn: array<string, mixed>|null}}
 * @phpstan-type Correction array{rank: int, type: string, label: string, theme: string, image: string|null, explanation: array{why: list<string>, example: string|null, source: string|null}, mine: float|null, theirs: float|null, error: bool, choices: list<array{id: int, label: string, correct: bool, mine: bool, theirs: bool}>, numeric: array{mine: string|null, right: string|null}|null}
 */
class QuizTournamentDuelReports
{
    public function __construct(
        private readonly QuizTournamentDuelRepository $duels,
        private readonly QuizTournamentDuelAnswerRepository $answers,
        private readonly QuizTournamentStandingsAssembler $assembler,
        private readonly QuizTournamentAccess $access,
        private readonly QuizAnswerChecker $checker,
        private readonly FileUploadService $files,
        private readonly UserAvatars $avatars,
    ) {
    }

    /**
     * H-resultat / H-defaite: the outcome, both sides, the strip per question, the rank now, the
     * errors by theme, the defeat's consolation, the activity, « Revanche ».
     *
     * @return array<string, mixed>
     */
    public function result(QuizTournamentDuel $duel, User $viewer, \DateTimeImmutable $now): array
    {
        $tournament = $duel->getTournament();
        $opponent = $duel->opponentOf($viewer) ?? $duel->getPlayerB();
        $avatars = $this->avatars->forUsers([$viewer, $opponent]);
        $me = $this->side($duel, $viewer, $avatars);
        $them = $this->side($duel, $opponent, $avatars);
        $outcome = QuizTournamentElo::outcome($me['score'], $them['score']);

        $corrections = $this->corrections($duel, $viewer);
        $standings = $this->assembler->assemble($tournament, $now);
        $line = $standings->lineFor($viewer->getId());

        $history = $this->duels->findFinishedFor($tournament, $viewer);
        $duelsBefore = 0;
        foreach ($history as $index => $past) {
            if ($past === $duel) {
                $duelsBefore = $index;
                break;
            }
        }

        $rematch = null;
        if ($this->access->canPlay($tournament, $viewer, $now) && $this->access->canPlay($tournament, $opponent, $now)) {
            $facts = $this->access->challengeFacts($tournament, $viewer, $opponent, $now);
            $rematch = ['left' => QuizTournamentDuelReading::challengesLeftToday(QuizTournamentChallengeRules::encountersToday($facts->pairEncounters, $now))];
        }

        return [
            'me' => $me,
            'them' => $them,
            'outcome' => $outcome > 0.5 ? 'win' : ($outcome < 0.5 ? 'loss' : 'draw'),
            'strips' => array_map(static fn (array $question): array => ['mine' => $question['mine'], 'theirs' => $question['theirs']], $corrections),
            'total' => \count($corrections),
            'errors' => \count(array_filter($corrections, static fn (array $question): bool => $question['error'])),
            'themes' => QuizTournamentDuelReading::errorsByTheme(array_map(static fn (array $question): array => ['theme' => $question['theme'], 'points' => $question['mine']], $corrections)),
            'consolation' => $outcome < 0.5 ? QuizTournamentDuelReading::consolation($me['before'], $me['after'], $them['before'], $duelsBefore) : null,
            'line' => $line,
            'activity' => null === $line ? 0 : min($line->row->entry->recentDuels, QuizTournamentStanding::ACTIVITY_DUELS),
            'rematch' => $rematch,
        ];
    }

    /**
     * Every question of the duel as the correction shows it to this player: their answer, the right
     * one, whether the other had it, the explanation.
     *
     * @return list<Correction>
     */
    public function corrections(QuizTournamentDuel $duel, User $viewer): array
    {
        $opponent = $duel->opponentOf($viewer);
        $byQuestion = [];
        foreach ($this->answers->findForDuel($duel) as $answer) {
            $byQuestion[$answer->getQuestion()->getRank()][(int) $answer->getPlayer()->getId()] = $answer;
        }

        $rows = [];
        foreach ($duel->getQuestions() as $question) {
            $rank = $question->getRank();
            $mine = $byQuestion[$rank][(int) $viewer->getId()] ?? null;
            $theirs = null === $opponent ? null : ($byQuestion[$rank][(int) $opponent->getId()] ?? null);

            $choices = [];
            foreach ($question->getChoices() as $choice) {
                $choices[] = [
                    'id' => $choice['id'],
                    'label' => $choice['label'],
                    'correct' => $choice['correct'],
                    'mine' => null !== $mine && \in_array($choice['id'], $mine->getSelectedChoiceIds(), true),
                    'theirs' => null !== $theirs && \in_array($choice['id'], $theirs->getSelectedChoiceIds(), true),
                ];
            }

            $numeric = null;
            if (QuestionType::Numerique === $question->getType()) {
                $unit = $question->getNumericUnit();
                $expected = $this->checker->expectedNumericValue($question);
                $value = $mine?->getNumericValue();
                $numeric = [
                    'mine' => null === $value ? null : trim(self::number($value, $question->getNumericDecimals()).' '.($mine->getNumericUnit() ?? ($question->isNumericUnitRequired() ? '' : (string) $unit))),
                    'right' => null === $expected ? null : trim(self::number($expected, $question->getNumericDecimals()).' '.$unit),
                ];
            }

            $image = $question->getImageStorageKey();
            $points = $mine?->getPoints();
            $rows[] = [
                'rank' => $rank,
                'type' => $question->getType()->value,
                'label' => (string) $question->getLabel(),
                'theme' => $question->getSourceQuizName(),
                'image' => null === $image ? null : $this->files->url($image),
                'explanation' => QuizTournamentDuelReading::explanationParts($question->getExplanation()),
                'mine' => $points,
                'theirs' => $theirs?->getPoints(),
                'error' => QuizTournamentDuelReading::isError($points),
                'choices' => $choices,
                'numeric' => $numeric,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return $rows;
    }

    /**
     * H-mes-duels: the totals, the rating line, the finished duels newest first.
     *
     * @return array{count: int, wins: int, draws: int, losses: int, line: array{points: list<int>, best: int, sinceStart: int}, chart: array{points: list<array{x: int|float, y: float, rating: int, duel: int}>, grid: list<array{y: float, value: int}>, polyline: string}, duels: list<array{duel: QuizTournamentDuel, opponent: User, outcome: string, mine: float, theirs: float, delta: int, after: int}>}
     */
    public function history(QuizTournament $tournament, User $viewer): array
    {
        $rows = [];
        $ratings = [];
        $counts = ['win' => 0, 'draw' => 0, 'loss' => 0];
        foreach ($this->duels->findFinishedFor($tournament, $viewer) as $duel) {
            $isA = $duel->getPlayerA() === $viewer;
            $mine = $isA ? $duel->getScoreA() : $duel->getScoreB();
            $theirs = $isA ? $duel->getScoreB() : $duel->getScoreA();
            $before = $isA ? $duel->getRatingBeforeA() : $duel->getRatingBeforeB();
            $after = ($isA ? $duel->getRatingAfterA() : $duel->getRatingAfterB()) ?? $before;
            $outcome = QuizTournamentElo::outcome($mine, $theirs);
            $key = $outcome > 0.5 ? 'win' : ($outcome < 0.5 ? 'loss' : 'draw');
            ++$counts[$key];
            $ratings[] = $after;
            $rows[] = [
                'duel' => $duel,
                'opponent' => $isA ? $duel->getPlayerB() : $duel->getPlayerA(),
                'outcome' => $key,
                'mine' => $mine,
                'theirs' => $theirs,
                'delta' => $after - $before,
                'after' => $after,
            ];
        }

        $line = QuizTournamentDuelReading::ratingLine($ratings);

        return [
            'count' => \count($rows),
            'wins' => $counts['win'],
            'draws' => $counts['draw'],
            'losses' => $counts['loss'],
            'line' => $line,
            'chart' => QuizTournamentRatingChart::of($line['points']),
            'duels' => array_reverse($rows),
        ];
    }

    /**
     * @param array<int, array{photo: string|null, drawn: array<string, mixed>|null}> $avatars
     *
     * @return Side
     */
    private function side(QuizTournamentDuel $duel, User $user, array $avatars): array
    {
        $isA = $duel->getPlayerA() === $user;
        $before = $isA ? $duel->getRatingBeforeA() : $duel->getRatingBeforeB();
        $after = ($isA ? $duel->getRatingAfterA() : $duel->getRatingAfterB()) ?? $before;

        return [
            'user' => $user,
            'score' => $isA ? $duel->getScoreA() : $duel->getScoreB(),
            'before' => $before,
            'after' => $after,
            'delta' => $after - $before,
            'avatar' => $avatars[(int) $user->getId()] ?? ['photo' => null, 'drawn' => null],
        ];
    }

    /** « 72 », « 9,81 »: French decimals, no trailing zero. */
    public static function number(float $value, int $decimals): string
    {
        $text = number_format($value, $decimals, ',', "\u{202F}");

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
