<?php

namespace App\Controller;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Topic;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Security\StructureAccessChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Read-only display of a Program's full curriculum (Topic/TopicGroup) - see
// App\Controller\ProgramTimetableSettingsController for the staff CRUD that manages this same
// data. Ported from a sister app's "syllabus" screen: the whole table is rendered server-side in
// one page, and DataTables (+ RowGroup) does all grouping/sorting/hour-total calculation
// client-side - no pagination, since a Program's topic list is small.
class ProgramSyllabusController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/syllabus', name: 'app_program_syllabus')]
    public function show(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, TopicRepository $topicRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $topics = $topicRepository->findAllForProgramOrderedByTopicGroup($program);

        $totalCmHours = 0;
        $totalTdHours = 0;
        $totalTpHours = 0;
        foreach ($topics as $topic) {
            $totalCmHours += $topic->getTargetCmHours();
            $totalTdHours += $topic->getTargetTdHours();
            $totalTpHours += $topic->getTargetTpHours();
        }

        return $this->render('program/syllabus.html.twig', [
            'program' => $program,
            'topics' => $topics,
            'totalCmHours' => $totalCmHours,
            'totalTdHours' => $totalTdHours,
            'totalTpHours' => $totalTpHours,
            'totalHours' => $totalCmHours + $totalTdHours + $totalTpHours,
            'optionStats' => $this->buildOptionStats($program, $topics),
        ]);
    }

    // One card's worth of hour totals per Option the Program actually has - a TopicGroup with no
    // Options of its own (the common case) counts toward every Option's card, since it's common
    // curriculum rather than specific to any one specialization (see TopicGroup's class docblock).
    /** @return list<array{option: Option, cmHours: int, tdHours: int, tpHours: int, totalHours: int}> */
    private function buildOptionStats(Program $program, array $topics): array
    {
        $stats = [];

        foreach ($program->getOptions() as $option) {
            $cmHours = 0;
            $tdHours = 0;
            $tpHours = 0;

            foreach ($topics as $topic) {
                /** @var Topic $topic */
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

    // Same visibility/feature-gate rule as App\Controller\ProgramSequenceInstanceController:
    // students/teachers see it for any Program they're visible in, staff always - and it requires
    // timetableManagementEnabled since Topic/TopicGroup are timetable-planning data (see
    // App\Entity\TopicGroup's docblock).
    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $program;
    }
}
