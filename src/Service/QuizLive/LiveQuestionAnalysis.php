<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use App\Entity\QuizLiveSession;
use App\Repository\QuizLiveAnswerRepository;

/**
 * A finished live contest read question by question, for its host's results page: how many of the
 * players got each question right - nobody's name, only the figures - and what the room voted on
 * each vote.
 *
 * A question counts the players **in the room when it closed**: closing a question writes a blank
 * answer for whoever said nothing (QuizLiveSessionService::closeQuestion()), so a silence is a
 * failure, while a player who joined later is not counted on a question they never saw. A question
 * the contest never reached has no rate at all.
 *
 * @phpstan-type QuestionRow array{number: int, label: string, typeKey: string, present: int, answered: int, right: int, rate: int|null}
 * @phpstan-type PollBar array{label: string|null, count: int, percent: int, none: bool}
 * @phpstan-type Poll array{number: int, label: string, present: int, answered: int, bars: list<PollBar>}
 */
final class LiveQuestionAnalysis
{
    public const array SORTS = ['rate_asc', 'rate_desc', 'number'];

    public function __construct(private readonly QuizLiveAnswerRepository $answers)
    {
    }

    /**
     * @return array{questions: list<QuestionRow>, polls: list<Poll>}
     */
    public function of(QuizLiveSession $session, string $sort): array
    {
        $byQuestion = [];
        foreach ($this->answers->findForSession($session) as $answer) {
            $byQuestion[(int) $answer->getInstanceQuestion()?->getId()][] = $answer;
        }

        $questions = [];
        $polls = [];
        foreach (array_values($session->getQuizInstance()?->getQuestions()->toArray() ?? []) as $index => $question) {
            $answers = $byQuestion[(int) $question->getId()] ?? [];
            $answered = \count(array_filter($answers, static fn ($answer): bool => null !== $answer->getResponse()));
            $label = (string) $question->getLabel();

            if (!$question->getType()->hasRightAnswer()) {
                $propositions = [];
                foreach ($question->getAnswers() as $rank => $proposition) {
                    $propositions[] = ['id' => (int) $proposition->getId(), 'label' => $proposition->getLabel() ?? '#'.($rank + 1)];
                }
                $polls[] = [
                    'number' => $index + 1,
                    'label' => $label,
                    'present' => \count($answers),
                    'answered' => $answered,
                    'bars' => self::bars($propositions, array_map(
                        static fn ($answer): ?array => null === $answer->getResponse() ? null : LiveResponse::fromArray($answer->getResponse())->answerIds,
                        $answers,
                    )),
                ];
                continue;
            }

            $right = \count(array_filter($answers, static fn ($answer): bool => true === $answer->getIsCorrect()));
            $questions[] = [
                'number' => $index + 1,
                'label' => $label,
                'typeKey' => $question->getType()->shortLabelKey(),
                'present' => \count($answers),
                'answered' => $answered,
                'right' => $right,
                'rate' => self::rate($right, \count($answers)),
            ];
        }

        return ['questions' => self::sort($questions, $sort), 'polls' => $polls];
    }

    /** Whole percents, as the rest of the results page; null for a question nobody was asked. */
    public static function rate(int $right, int $present): ?int
    {
        return $present > 0 ? (int) round($right / $present * 100) : null;
    }

    /**
     * Weakest first unless asked otherwise; a question never played always last.
     *
     * @template T of array{number: int, rate: int|null}
     *
     * @param list<T> $rows
     *
     * @return list<T>
     */
    public static function sort(array $rows, string $sort): array
    {
        usort($rows, static fn (array $a, array $b): int => match ($sort) {
            'number' => $a['number'] <=> $b['number'],
            'rate_desc' => [null === $a['rate'], -($a['rate'] ?? 0), $a['number']] <=> [null === $b['rate'], -($b['rate'] ?? 0), $b['number']],
            default => [null === $a['rate'], $a['rate'] ?? 0, $a['number']] <=> [null === $b['rate'], $b['rate'] ?? 0, $b['number']],
        });

        return $rows;
    }

    /**
     * One bar per proposition in the order the room saw them, then one for the players who did
     * not vote - each out of the players in the room, so a single-choice vote adds up to 100 %.
     *
     * @param list<array{id: int, label: string}> $propositions
     * @param list<list<int>|null>                $responses    the propositions each player tapped, null for a silence
     *
     * @return list<PollBar>
     */
    public static function bars(array $propositions, array $responses): array
    {
        $present = \count($responses);
        $counts = [];
        $silent = 0;
        foreach ($responses as $ids) {
            if (null === $ids) {
                ++$silent;
                continue;
            }
            foreach (array_unique($ids) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        $bars = [];
        foreach ($propositions as $proposition) {
            $count = $counts[$proposition['id']] ?? 0;
            $bars[] = ['label' => $proposition['label'], 'count' => $count, 'percent' => self::rate($count, $present) ?? 0, 'none' => false];
        }
        $bars[] = ['label' => null, 'count' => $silent, 'percent' => self::rate($silent, $present) ?? 0, 'none' => true];

        return $bars;
    }
}
