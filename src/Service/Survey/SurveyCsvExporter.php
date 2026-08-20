<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveyResponse;
use App\Repository\SurveyResponseRepository;

/**
 * The detail of a campaign, as a CSV.
 *
 * On an anonymous campaign it exports **without any identity column and in display_key order**
 * (design/validated/surveys.md §6). That ordering is not a nicety: a file sorted by id or by
 * submitted_at is de-anonymising all on its own, since the first row is the first person to have
 * answered - and in a class of twenty everybody knows who answers first. The timestamps are left
 * out of the file for the same reason.
 */
class SurveyCsvExporter
{
    public function __construct(private readonly SurveyResponseRepository $responses)
    {
    }

    /**
     * The whole file as a string - a campaign's detail is a few dozen rows, never a stream.
     *
     * The separator is a semicolon and the file opens with a UTF-8 BOM, like every other export of
     * this repository: it is opened in Excel, in French, and without either the accents break and
     * the columns end up in one cell.
     */
    public function export(SurveyCampaign $campaign): string
    {
        $questions = $campaign->answerableQuestions();

        $header = [$campaign->isAnonymous() ? 'Réponse' : 'Répondant'];
        foreach ($questions as $question) {
            $header[] = $this->shortLabel($question);
        }

        $rows = [$header];

        foreach ($this->responses->findSubmitted($campaign) as $response) {
            $rows[] = $this->rowFor($campaign, $response, $questions);
        }

        $csv = "\u{FEFF}";
        foreach ($rows as $row) {
            $csv .= implode(';', array_map($this->escape(...), $row))."\r\n";
        }

        return $csv;
    }

    public function filename(SurveyCampaign $campaign): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $campaign->getName()) ?? 'sondage';

        return trim(strtolower($slug), '-').'.csv';
    }

    /**
     * @param list<SurveyCampaignQuestion> $questions
     *
     * @return list<string>
     */
    private function rowFor(SurveyCampaign $campaign, SurveyResponse $response, array $questions): array
    {
        // The identity column: the display key on an anonymous campaign, and nothing else - there
        // is no name stored to leave out, which is the whole point.
        $row = [$campaign->isAnonymous()
            ? $response->shortDisplayKey()
            : ($response->getRespondent()?->getDisplayName() ?? $response->getRespondent()?->getUsername() ?? '')];

        foreach ($questions as $question) {
            $answer = $response->answerFor($question);

            if (null === $answer) {
                $row[] = '';

                continue;
            }

            if (!$question->getType()->hasAnswers()) {
                $row[] = (string) $answer->getFreeText();

                continue;
            }

            // Several picks joined by a middot, in the order they were stored - which for a ranking
            // question is the rank the respondent gave them.
            $labels = [];
            foreach ($answer->getSelected() as $selected) {
                $labels[] = (string) $selected->getCampaignAnswer()?->getLabel();
            }
            $row[] = implode(' · ', $labels);
        }

        return $row;
    }

    /** Column headers are shortened: a full statement makes a spreadsheet unreadable. */
    private function shortLabel(SurveyCampaignQuestion $question): string
    {
        $label = trim(strip_tags($question->getLabel()));

        return mb_strlen($label) > 60 ? mb_substr($label, 0, 57).'…' : $label;
    }

    private function escape(string $value): string
    {
        return '"'.str_replace('"', '""', str_replace(["\r\n", "\n", "\r"], ' ', $value)).'"';
    }
}
