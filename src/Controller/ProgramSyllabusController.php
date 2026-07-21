<?php

namespace App\Controller;

use App\Entity\Program;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Security\StructureAccessChecker;
use App\Service\TopicHourStatsCalculator;
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
    public function show(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, TopicRepository $topicRepository, TopicHourStatsCalculator $hourStatsCalculator): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $topics = $topicRepository->findAllForProgramOrderedByTopicGroup($program);

        return $this->render('program/syllabus.html.twig', array_merge([
            'program' => $program,
            'topics' => $topics,
        ], $hourStatsCalculator->calculate($program, $topics)));
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
