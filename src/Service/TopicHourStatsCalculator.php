<?php

namespace App\Service;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Topic;

/**
 * The CM/TD/TP hour-total cards shown above a Program's Topic list - shared between
 * App\Controller\ProgramSyllabusController (read-only "Syllabus" view) and
 * App\Controller\ProgramTimetableSettingsController's "Matières" settings tab, which list the
 * same active Topics (App\Repository\TopicRepository::findAllForProgramOrderedByTopicGroup()/
 * findAllForProgramOrderedByOption() differ only in sort order, not in which rows they return),
 * just via two different pages/templates.
 */
class TopicHourStatsCalculator
{
    /**
     * @param list<Topic> $topics
     *
     * @return array{totalCmHours: int, totalTdHours: int, totalTpHours: int, totalHours: int, optionStats: list<array{option: Option, cmHours: int, tdHours: int, tpHours: int, totalHours: int}>}
     */
    public function calculate(Program $program, array $topics): array
    {
        $totalCmHours = 0;
        $totalTdHours = 0;
        $totalTpHours = 0;
        foreach ($topics as $topic) {
            $totalCmHours += $topic->getTargetCmHours();
            $totalTdHours += $topic->getTargetTdHours();
            $totalTpHours += $topic->getTargetTpHours();
        }

        return [
            'totalCmHours' => $totalCmHours,
            'totalTdHours' => $totalTdHours,
            'totalTpHours' => $totalTpHours,
            'totalHours' => $totalCmHours + $totalTdHours + $totalTpHours,
            'optionStats' => $this->buildOptionStats($program, $topics),
        ];
    }

    // One card's worth of hour totals per Option the Program actually has - a TopicGroup with no
    // Options of its own (the common case) counts toward every Option's card, since it's common
    // curriculum rather than specific to any one specialization (see TopicGroup's class docblock).
    /**
     * @param list<Topic> $topics
     *
     * @return list<array{option: Option, cmHours: int, tdHours: int, tpHours: int, totalHours: int}>
     */
    private function buildOptionStats(Program $program, array $topics): array
    {
        $stats = [];

        foreach ($program->getOptions() as $option) {
            $cmHours = 0;
            $tdHours = 0;
            $tpHours = 0;

            foreach ($topics as $topic) {
                $groupOptions = $topic->getTopicGroup()->getOptions();

                if (!$groupOptions->isEmpty() && !$groupOptions->contains($option)) {
                    continue;
                }

                $cmHours += $topic->getTargetCmHours();
                $tdHours += $topic->getTargetTdHours();
                $tpHours += $topic->getTargetTpHours();
            }

            $stats[] = [
                'option' => $option,
                'cmHours' => $cmHours,
                'tdHours' => $tdHours,
                'tpHours' => $tpHours,
                'totalHours' => $cmHours + $tdHours + $tpHours,
            ];
        }

        return $stats;
    }
}
