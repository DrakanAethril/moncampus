<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveySeries;
use App\Entity\User;
use App\Repository\SurveyResponseRepository;
use App\Repository\SurveyTargetRepository;

/**
 * The waves of a series, side by side - and the individual comparison, which refuses rather than
 * hides (design/validated/surveys.md §7.15).
 *
 * The alignment itself is SurveyWaveAlignment's, on primitives; this class is what feeds it from
 * the database and dresses the result for the screen.
 */
class SurveyComparison
{
    public function __construct(
        private readonly SurveyResults $results,
        private readonly SurveyTargetRepository $targets,
        private readonly SurveyResponseRepository $responses,
    ) {
    }

    /**
     * Every wave of the series with its results, plus which questions may be compared at all.
     *
     * @param list<SurveyCampaign> $waves oldest first
     *
     * @return array{
     *     alignment: SurveyWaveAlignment,
     *     questions: list<array{key: string, label: string, isScale: bool, comparable: bool, answers: list<string>, byWave: array<int, array{percents: array<int, float>, average: float|null, counts: array<int, int>}>}>,
     *     needsCurve: bool,
     * }
     */
    public function compare(array $waves): array
    {
        $keysByWave = [];
        foreach ($waves as $wave) {
            $questions = [];
            foreach ($wave->answerableQuestions() as $question) {
                $questions[$question->getComparisonKey()] = $question->getType()->value;
            }
            $keysByWave[$wave->getWaveNumber()] = $questions;
        }

        $alignment = SurveyWaveAlignment::align($keysByWave);
        $comparable = array_flip($alignment->comparableKeys());

        // The results of every wave, read once each - the aggregation is two queries per wave, and
        // a series of three waves must not become one query per question per wave.
        $resultsByWave = [];
        foreach ($waves as $wave) {
            $byKey = [];
            foreach ($this->results->forCampaign($wave) as $result) {
                $byKey[$result->questionId] = $result;
            }
            $resultsByWave[$wave->getWaveNumber()] = $byKey;
        }

        $questions = [];
        $seen = [];

        foreach ($waves as $wave) {
            foreach ($wave->answerableQuestions() as $question) {
                $key = $question->getComparisonKey();

                if (isset($seen[$key]) || !$question->getType()->hasAnswers()) {
                    continue;
                }
                $seen[$key] = true;

                $questions[] = [
                    'key' => $key,
                    'label' => $question->getLabel(),
                    'isScale' => $question->isScale(),
                    'comparable' => isset($comparable[$key]),
                    'answers' => $this->answerLabelsOf($question),
                    'byWave' => $this->byWave($waves, $key, $resultsByWave),
                ];
            }
        }

        return [
            'alignment' => $alignment,
            'questions' => $questions,
            'needsCurve' => SurveyWaveAlignment::needsCurve(\count($waves)),
        ];
    }

    /**
     * One question's numbers in every wave, keyed by wave number. The answers are matched by their
     * position, which is safe precisely because a comparable question has, by construction, the
     * same answers in the same order.
     *
     * @param list<SurveyCampaign>                                    $waves
     * @param array<int, array<int, SurveyQuestionResult>>             $resultsByWave
     *
     * @return array<int, array{percents: array<int, float>, average: float|null, counts: array<int, int>}>
     */
    private function byWave(array $waves, string $key, array $resultsByWave): array
    {
        $byWave = [];

        foreach ($waves as $wave) {
            $question = $this->questionWithKey($wave, $key);

            if (null === $question) {
                continue;
            }

            $result = $resultsByWave[$wave->getWaveNumber()][(int) $question->getId()] ?? null;

            if (null === $result) {
                continue;
            }

            $percents = [];
            $counts = [];
            foreach ($question->getAnswers() as $position => $answer) {
                $percents[$position] = $result->percentFor((int) $answer->getId());
                $counts[$position] = $result->countFor((int) $answer->getId());
            }

            $byWave[$wave->getWaveNumber()] = [
                'percents' => $percents,
                'average' => $result->scaleAverage(),
                'counts' => $counts,
            ];
        }

        return $byWave;
    }

    private function questionWithKey(SurveyCampaign $campaign, string $key): ?SurveyCampaignQuestion
    {
        foreach ($campaign->getQuestions() as $question) {
            if ($question->getComparisonKey() === $key) {
                return $question;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function answerLabelsOf(SurveyCampaignQuestion $question): array
    {
        $labels = [];
        foreach ($question->getAnswers() as $answer) {
            $labels[] = $answer->getLabel();
        }

        return $labels;
    }

    /**
     * The individual comparison - the only screen of the feature that looks at a person rather than
     * at a population.
     *
     * It is justified (a positioning *is* individual, and is never anonymous) but it is fenced in:
     * it refuses outright if either wave is anonymous - including for an administrator - and lists
     * only the people present in the target of both waves.
     *
     * @return array{refusal: SurveyComparisonRefusal|null, rows: list<array{user: User, first: string|null, second: string|null, movement: int|null}>, question: SurveyCampaignQuestion|null}
     */
    public function individual(SurveySeries $series, SurveyCampaign $first, SurveyCampaign $second, ?string $comparisonKey = null): array
    {
        unset($series);

        $refusal = SurveyWaveAlignment::individualRefusal($first->isAnonymous(), $second->isAnonymous());

        if (null !== $refusal) {
            return ['refusal' => $refusal, 'rows' => [], 'question' => null];
        }

        $shared = SurveyWaveAlignment::sharedTarget(
            $this->targets->findTargetedUserIds($first),
            $this->targets->findTargetedUserIds($second),
        );

        if ([] === $shared) {
            return ['refusal' => SurveyComparisonRefusal::NoSharedTarget, 'rows' => [], 'question' => null];
        }

        $question = null !== $comparisonKey
            ? $this->questionWithKey($first, $comparisonKey)
            : ($first->answerableQuestions()[0] ?? null);

        if (null === $question || !$question->getType()->hasAnswers()) {
            return ['refusal' => SurveyComparisonRefusal::NotEnoughWaves, 'rows' => [], 'question' => null];
        }

        $secondQuestion = $this->questionWithKey($second, $question->getComparisonKey());

        if (null === $secondQuestion) {
            // The question was edited between the waves: no column, exactly as in the overall
            // comparison (§7.15, rule 3).
            return ['refusal' => SurveyComparisonRefusal::NotEnoughWaves, 'rows' => [], 'question' => null];
        }

        $firstAnswers = $this->answersByUser($first, $question);
        $secondAnswers = $this->answersByUser($second, $secondQuestion);

        $rows = [];
        foreach ($this->targets->findAllFor($first) as $target) {
            $user = $target->getUser();
            $id = $user?->getId();

            if (null === $user || null === $id || !\in_array($id, $shared, true)) {
                continue;
            }

            $before = $firstAnswers[$id] ?? null;
            $after = $secondAnswers[$id] ?? null;

            $rows[] = [
                'user' => $user,
                'first' => $before['label'] ?? null,
                'second' => $after['label'] ?? null,
                // The movement is a difference of ranks, which only means something on a scale -
                // and « = » is a real answer, not a missing one.
                'movement' => null !== $before && null !== $after ? $after['rank'] - $before['rank'] : null,
            ];
        }

        return ['refusal' => null, 'rows' => $rows, 'question' => $question];
    }

    /**
     * What each person answered to one question, keyed by user id. Only ever called on a nominative
     * campaign - on an anonymous one there is no respondent stored to key on, which is exactly why
     * individual() refuses before reaching here.
     *
     * @return array<int, array{label: string, rank: int}>
     */
    private function answersByUser(SurveyCampaign $campaign, SurveyCampaignQuestion $question): array
    {
        $answers = [];

        foreach ($this->responses->findSubmitted($campaign) as $response) {
            $respondent = $response->getRespondent();
            $id = $respondent?->getId();

            if (null === $id) {
                continue;
            }

            $answer = $response->answerFor($question);
            $selected = $answer?->getSelected()->first();

            if (false === $selected || null === $selected) {
                continue;
            }

            $campaignAnswer = $selected->getCampaignAnswer();

            if (null === $campaignAnswer) {
                continue;
            }

            $answers[$id] = ['label' => $campaignAnswer->getLabel(), 'rank' => $campaignAnswer->getOrderIndex()];
        }

        return $answers;
    }
}
