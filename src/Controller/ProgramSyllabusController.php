<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Enum\Feature;
use App\Enum\ProgramSyllabusMode;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Security\StructureAccessChecker;
use App\Service\FileUploadService;
use App\Service\TopicHourStatsCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Read-only display of a Program's full curriculum (Topic/TopicGroup) - see
// App\Controller\ProgramTimetableSettingsController for the staff CRUD that manages this same
// data. Ported from a sister app's "syllabus" screen: the whole table is rendered server-side in
// one page, and DataTables (+ RowGroup) does all grouping/sorting/hour-total calculation
// client-side - no pagination, since a Program's topic list is small.
#[RequiresFeature(Feature::Progression)]
class ProgramSyllabusController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    // Same route either way - when syllabusMode is File, it serves the uploaded PDF directly
    // instead of the Topic/TopicGroup page, so the nav entry never needs to know which mode is
    // configured.
    #[Route(path: '/programs/{id}/syllabus', name: 'app_program_syllabus')]
    public function show(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, TopicRepository $topicRepository, TopicHourStatsCalculator $hourStatsCalculator, FileUploadService $fileUploadService): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        if (ProgramSyllabusMode::File === $program->getSyllabusMode() && null !== $program->getSyllabusFileKey()) {
            return new RedirectResponse($fileUploadService->url($program->getSyllabusFileKey()));
        }

        $topics = $topicRepository->findAllForProgramOrderedByTopicGroup($program);

        return $this->render('program/syllabus.html.twig', array_merge([
            'program' => $program,
            'topics' => $topics,
        ], $hourStatsCalculator->calculate($program, $topics)));
    }

    // Same visibility/feature-gate rule as App\Controller\ProgramSequenceInstanceController:
    // students/teachers see it for any Program they're visible in, staff always - and it requires
    // timetableManagementEnabled since Topic/TopicGroup are timetable-planning data (see
    // App\Entity\TopicGroup's docblock). Program::$syllabusVisibility layers a finer-grained role
    // tier on top of that same feature flag.
    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());
        $this->assertProgramFeatureEnabled($program->getSyllabusVisibility()->allowsRoles($this->getUser()?->getRoles() ?? []));

        return $program;
    }
}
