<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveyResponse;
use App\Repository\SurveyResponseRepository;
use App\Repository\SurveyTargetRepository;
use Doctrine\DBAL\Connection;

/**
 * The aggregation of one campaign - in **two queries, whatever the number of questions**
 * (design/validated/surveys.md §7.4).
 *
 * A « for each question, count » loop over a 25-question survey means 25 queries per display. That
 * is the N+1 already fixed once on the home dashboard, and it is not being reintroduced here: one
 * query counts every proposed answer of the campaign at once, a second counts the respondents,
 * globally and per question.
 *
 * Written in SQL through the DBAL rather than in DQL because it is a pure aggregate over four
 * tables that returns no entity - hydrating SurveyResponseSelectedAnswer objects to count them
 * would be the slow way of doing arithmetic.
 */
class SurveyResults
{
    /**
     * Under three responses an anonymous campaign shows no distribution at all: on a target of four
     * people, a two-bar histogram points at somebody (§7.6).
     */
    public const int ANONYMOUS_DISCLOSURE_THRESHOLD = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly SurveyTargetRepository $targets,
        private readonly SurveyResponseRepository $responses,
    ) {
    }

    /**
     * Every question's numbers, in the snapshot's order - intertitles excluded, since they measure
     * nothing (§7.13).
     *
     * @return list<SurveyQuestionResult>
     */
    public function forCampaign(SurveyCampaign $campaign): array
    {
        $questions = $campaign->answerableQuestions();

        if ([] === $questions) {
            return [];
        }

        $questionIds = array_map(static fn (SurveyCampaignQuestion $q): int => (int) $q->getId(), $questions);

        $counts = $this->countsByAnswer($questionIds);
        $answeredPerQuestion = $this->answeredPerQuestion($questionIds);
        $targeted = $this->targets->countFor($campaign);

        $results = [];
        foreach ($questions as $question) {
            $id = (int) $question->getId();

            $questionCounts = [];
            $orderIndexes = [];
            $rankSums = [];
            foreach ($question->getAnswers() as $answer) {
                $answerId = (int) $answer->getId();
                $row = $counts[$answerId] ?? ['count' => 0, 'rankSum' => 0];
                $questionCounts[$answerId] = $row['count'];
                $rankSums[$answerId] = $row['rankSum'];
                $orderIndexes[$answerId] = $answer->getOrderIndex();
            }

            $results[] = new SurveyQuestionResult(
                questionId: $id,
                type: $question->getType()->value,
                label: $question->getLabel(),
                isScale: $question->isScale(),
                answered: $answeredPerQuestion[$id] ?? 0,
                targeted: $targeted,
                counts: $questionCounts,
                orderIndexes: $orderIndexes,
                rankSums: $rankSums,
            );
        }

        return $results;
    }

    /**
     * Query 1 - how many people picked each proposed answer, and the sum of the ranks it was given.
     *
     * The rank sum is free here, in the very same query, which is what makes the Ordre type's
     * average rank cost nothing rather than a second pass.
     *
     * @param list<int> $questionIds
     *
     * @return array<int, array{count: int, rankSum: int}>
     */
    private function countsByAnswer(array $questionIds): array
    {
        $sql = <<<'SQL'
            SELECT a.id AS answer_id,
                   COUNT(s.id) AS answer_count,
                   COALESCE(SUM(s.order_index), 0) AS rank_sum
            FROM survey_campaign_answer a
            LEFT JOIN survey_response_selected_answer s ON s.survey_campaign_answer_id = a.id
            LEFT JOIN survey_response_answer ra ON ra.id = s.survey_response_answer_id
            LEFT JOIN survey_response r ON r.id = ra.survey_response_id AND r.submitted_at IS NOT NULL
            WHERE a.survey_campaign_question_id IN (:questions)
              AND (s.id IS NULL OR r.id IS NOT NULL)
            GROUP BY a.id
            SQL;

        /** @var list<array{answer_id: int|string, answer_count: int|string, rank_sum: int|string}> $rows */
        $rows = $this->connection->executeQuery(
            $sql,
            ['questions' => $questionIds],
            ['questions' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['answer_id']] = [
                'count' => (int) $row['answer_count'],
                'rankSum' => (int) $row['rank_sum'],
            ];
        }

        return $counts;
    }

    /**
     * Query 2 - how many people actually answered each question.
     *
     * A row exists even for a question that was seen and skipped, so what counts is not the row's
     * existence but whether it carries something: a picked answer, or a non-empty free text. That
     * is what tells « vue et passée » apart from « jamais atteinte ».
     *
     * @param list<int> $questionIds
     *
     * @return array<int, int>
     */
    private function answeredPerQuestion(array $questionIds): array
    {
        $sql = <<<'SQL'
            SELECT ra.survey_campaign_question_id AS question_id, COUNT(DISTINCT ra.id) AS answered
            FROM survey_response_answer ra
            JOIN survey_response r ON r.id = ra.survey_response_id AND r.submitted_at IS NOT NULL
            LEFT JOIN survey_response_selected_answer s ON s.survey_response_answer_id = ra.id
            WHERE ra.survey_campaign_question_id IN (:questions)
              AND (s.id IS NOT NULL OR (ra.free_text IS NOT NULL AND TRIM(ra.free_text) <> ''))
            GROUP BY ra.survey_campaign_question_id
            SQL;

        /** @var list<array{question_id: int|string, answered: int|string}> $rows */
        $rows = $this->connection->executeQuery(
            $sql,
            ['questions' => $questionIds],
            ['questions' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $answered = [];
        foreach ($rows as $row) {
            $answered[(int) $row['question_id']] = (int) $row['answered'];
        }

        return $answered;
    }

    /**
     * The response rate of the campaign as a whole - « 18 / 24 · 75 % ». Always shown, threshold or
     * not: it says nothing about the content.
     *
     * @return array{targeted: int, responded: int, percent: float}
     */
    public function responseRate(SurveyCampaign $campaign): array
    {
        $rate = $this->targets->responseRate($campaign);
        $percent = $rate['targeted'] > 0 ? $rate['responded'] * 100 / $rate['targeted'] : 0.0;

        return ['targeted' => $rate['targeted'], 'responded' => $rate['responded'], 'percent' => $percent];
    }

    /**
     * The verbatims of one Commentaire question - the non-empty free texts, to be read.
     *
     * Deliberately nothing else: no word cloud, no sentiment, no automatic summary. A word cloud
     * gives the illusion of having read; here one is given the text. On an anonymous campaign they
     * come in display_key order, which is the only order that does not say who spoke first.
     *
     * @return list<array{key: string, respondent: string|null, text: string}>
     */
    public function verbatims(SurveyCampaign $campaign, SurveyCampaignQuestion $question): array
    {
        $verbatims = [];

        foreach ($this->responses->findSubmitted($campaign) as $response) {
            $answer = $response->answerFor($question);
            $text = trim((string) $answer?->getFreeText());

            if ('' === $text) {
                continue;
            }

            $verbatims[] = [
                'key' => $response->shortDisplayKey(),
                // Null on an anonymous campaign - there is no name stored to show, to anybody.
                'respondent' => $campaign->isAnonymous() ? null : $this->nameOf($response),
                'text' => $text,
            ];
        }

        return $verbatims;
    }

    private function nameOf(SurveyResponse $response): ?string
    {
        $respondent = $response->getRespondent();

        return null === $respondent ? null : ($respondent->getDisplayName() ?: $respondent->getUsername());
    }

    /** Whether this campaign's distributions may be shown at all - see the threshold above. */
    public function isDisclosable(SurveyCampaign $campaign): bool
    {
        if (!$campaign->isAnonymous()) {
            return true;
        }

        return $this->responses->countSubmitted($campaign) >= self::ANONYMOUS_DISCLOSURE_THRESHOLD;
    }
}
