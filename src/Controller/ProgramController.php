<?php

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\LessonSessionRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\ProgramTeacherOptionRepository;
use App\Security\StructureAccessChecker;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipCalendarBuilder;
use App\Service\LessonSessionEventFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

// Reached via the Section > Année scolaire > Classe nav menu. The "Paramétrage" entry lives in
// ProgramSettingsController instead, since it's grown into its own tabbed feature.
class ProgramController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/students', name: 'app_program_students')]
    public function students(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/students.html.twig', [
            'program' => $program,
            'students' => $this->sortedByName($program->getStudents()->toArray()),
            'optionsByStudentId' => $studentOptionRepository->findOptionsByStudentForProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/teachers', name: 'app_program_teachers')]
    public function teachers(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramTeacherOptionRepository $teacherOptionRepository): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/teachers.html.twig', [
            'program' => $program,
            'teachers' => $this->sortedByName($program->getTeachers()->toArray()),
            'optionsByTeacherId' => $teacherOptionRepository->findOptionsByTeacherForProgram($program),
            // Students see "S. Tharaud" instead of "Sébastien Tharaud" - teachers/staff viewing
            // this same page (e.g. a teacher checking their own team) still see the full name.
            'politeNames' => $this->isGranted('ROLE_STUDENT'),
        ]);
    }

    /**
     * @param list<User> $users
     *
     * @return list<User>
     */
    private function sortedByName(array $users): array
    {
        usort($users, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $users;
    }

    #[Route(path: '/programs/{id}/timetable', name: 'app_program_timetable')]
    public function timetable(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());

        return $this->render('program/timetable.html.twig', ['program' => $program]);
    }

    #[Route(path: '/programs/{id}/timetable/feed', name: 'app_program_timetable_feed')]
    public function timetableFeed(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, LessonSessionRepository $lessonSessionRepository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $this->assertProgramFeatureEnabled($program->isTimetableManagementEnabled());
        $sessions = $lessonSessionRepository->findForProgram($program);

        return $this->json(array_map(
            static fn (LessonSession $session): array => $eventFormatter->format($session, editable: false),
            $sessions,
        ));
    }

    #[Route(path: '/programs/{id}/alternance-calendar/pdf', name: 'app_program_alternance_calendar_pdf')]
    public function alternanceCalendarPdf(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, PeriodRepository $periodRepository, InternshipCalendarBuilder $calendarBuilder, GotenbergClient $gotenbergClient): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $this->assertProgramFeatureEnabled($program->isAlternanceCalendarEnabled());

        $schoolYear = $program->getSchoolYear();
        $periods = $periodRepository->findAllActiveForProgram($program);

        $html = $this->renderView('program/alternance_calendar_pdf.html.twig', [
            'program' => $program,
            'calendarMonths' => null !== $schoolYear ? $calendarBuilder->build($schoolYear, $periods) : [],
            'calendarLegend' => $calendarBuilder->buildLegend($periods),
            'assetBaseUrl' => 'http://php',
        ]);

        try {
            $pdf = $gotenbergClient->convertHtmlToPdf($html);
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'alternanceCalendarPdfExportFailedFlashMessage');

            return $this->redirectToRoute('app_program_students', ['id' => $program->getId()]);
        }

        $filename = (new AsciiSlugger())->slug($program->getShortName())->lower()->toString();

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('calendrier-alternance-%s.pdf', $filename)),
        ]);
    }

    // Students/teachers/timetable pages are reachable under the same rule as the nav entries
    // that link to them: staff/admin see every Program, a student or teacher only one they're
    // actually enrolled in/teaching - see StructureAccessChecker::isProgramVisible().
    private function findOrDenyAccess(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$accessChecker->isProgramVisible($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }
}
