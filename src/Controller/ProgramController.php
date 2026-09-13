<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Enum\Feature;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Repository\LessonSessionRepository;
use App\Repository\PeriodRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\ProgramTeacherOptionRepository;
use App\Security\ProgramTimetableAccess;
use App\Security\StructureAccessChecker;
use App\Service\Accommodation\AccommodationResolver;
use App\Service\ClassRoster;
use App\Service\FileUploadService;
use App\Service\GotenbergClient;
use App\Service\GotenbergUnavailableException;
use App\Service\InternshipCalendarBuilder;
use App\Service\LessonSessionEventFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

// Reached via the Section > Année scolaire > Classe nav menu. The "Paramétrage" entry lives in
// App\Controller\Program\Settings* instead, since it's grown into its own tabbed feature.
class ProgramController extends AbstractController
{
    use CalendarFeedRangeTrait;
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/students', name: 'app_program_students')]
    public function students(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramStudentOptionRepository $studentOptionRepository, ClassRoster $roster, AccommodationResolver $accommodations): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $students = $roster->ordered($program->getStudents()->toArray());

        // Who holds an aménagement is shown to the teaching side only - this screen is reached by
        // the class's own students too (findOrDenyAccess() lets an enrolled student in), and a
        // classmate's particular needs are none of their business. Built here rather than hidden in
        // the template on purpose: what a student must not see, a student is not sent.
        $accommodationsByStudentId = [];
        if ($accessChecker->isProgramTeacher($program)) {
            foreach ($students as $student) {
                $label = $accommodations->forUser($student)->quizExtraTimeLabel();
                if (null !== $label) {
                    $accommodationsByStudentId[$student->getId()] = $label;
                }
            }
        }

        return $this->render('program/students.html.twig', [
            'program' => $program,
            'students' => $students,
            'optionsByStudentId' => $studentOptionRepository->findOptionsByStudentForProgram($program),
            'accommodationsByStudentId' => $accommodationsByStudentId,
        ]);
    }

    #[Route(path: '/programs/{id}/teachers', name: 'app_program_teachers')]
    public function teachers(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramTeacherOptionRepository $teacherOptionRepository, ClassRoster $roster): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);

        return $this->render('program/teachers.html.twig', [
            'program' => $program,
            'teachers' => $roster->ordered($program->getTeachers()->toArray()),
            'optionsByTeacherId' => $teacherOptionRepository->findOptionsByTeacherForProgram($program),
            // Students see "S. Tharaud" instead of "Sébastien Tharaud" - teachers/staff viewing
            // this same page (e.g. a teacher checking their own team) still see the full name.
            'politeNames' => $this->isGranted('ROLE_STUDENT'),
        ]);
    }

    #[RequiresFeature(Feature::Timetable)]
    #[Route(path: '/programs/{id}/timetable', name: 'app_program_timetable')]
    public function timetable(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramTimetableAccess $timetableAccess): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        // The feature being lit for a role never overrides what the formation decided: the tier
        // is the second half of the same rule, and the screen answers 404 without it - see
        // App\Security\ProgramTimetableAccess.
        $this->assertProgramFeatureEnabled($timetableAccess->isVisible($program));

        return $this->render('program/timetable.html.twig', ['program' => $program]);
    }

    #[RequiresFeature(Feature::Timetable)]
    #[Route(path: '/programs/{id}/timetable/feed', name: 'app_program_timetable_feed')]
    public function timetableFeed(int $id, Request $request, ProgramRepository $repository, StructureAccessChecker $accessChecker, ProgramTimetableAccess $timetableAccess, LessonSessionRepository $lessonSessionRepository, LessonSessionEventFormatter $eventFormatter): JsonResponse
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        // The feed carries the same sessions as the screen and answers the same way - guarding the
        // page alone would leave the data one URL away.
        $this->assertProgramFeatureEnabled($timetableAccess->isVisible($program));
        [$start, $end] = $this->calendarFeedRange($request);
        $sessions = $lessonSessionRepository->findForProgramBetween($program, $start, $end);

        return $this->json(array_map(
            static fn (LessonSession $session): array => $eventFormatter->format($session, editable: false),
            $sessions,
        ));
    }

    // Same route either way - when alternanceCalendarMode is File, it serves the uploaded PDF
    // directly instead of generating one from PeriodGroup data, so the nav entry never needs to
    // know which mode is configured.
    #[RequiresFeature(Feature::MyAlternance)]
    #[Route(path: '/programs/{id}/alternance-calendar/pdf', name: 'app_program_alternance_calendar_pdf')]
    public function alternanceCalendarPdf(int $id, ProgramRepository $repository, StructureAccessChecker $accessChecker, PeriodRepository $periodRepository, InternshipCalendarBuilder $calendarBuilder, GotenbergClient $gotenbergClient, FileUploadService $fileUploadService, TranslatorInterface $translator): Response
    {
        $program = $this->findOrDenyAccess($id, $repository, $accessChecker);
        $this->assertProgramFeatureEnabled($program->getAlternanceCalendarVisibility()->allowsRoles($this->getUser()?->getRoles() ?? []));

        if (ProgramAlternanceCalendarMode::File === $program->getAlternanceCalendarMode() && null !== $program->getAlternanceCalendarFileKey()) {
            // See ProgramSyllabusController: an uploaded document carries no name, so the
            // formation lends it one.
            return new RedirectResponse($fileUploadService->downloadUrl(
                $program->getAlternanceCalendarFileKey(),
                $translator->trans('programAlternanceCalendarDocumentFilename', ['%program%' => $program->getShortName()]),
            ));
        }

        $startDate = $program->getEffectiveStartDate();
        $endDate = $program->getEffectiveEndDate();
        $periods = $periodRepository->findAllActiveForProgram($program);

        $html = $this->renderView('program/alternance_calendar_pdf.html.twig', [
            'program' => $program,
            'calendarMonths' => (null !== $startDate && null !== $endDate) ? $calendarBuilder->build($startDate, $endDate, $periods) : [],
            'calendarLegend' => $calendarBuilder->buildLegend($periods),
            'assetBaseUrl' => 'http://php',
        ]);

        try {
            $pdf = $gotenbergClient->convertHtmlToPdf($html);
        } catch (GotenbergUnavailableException) {
            $this->addFlash('error', 'alternanceCalendarPdfExportFailedFlashMessage');

            return $this->redirectToRoute('app_program_students', ['id' => $program->getId()]);
        }

        $filename = (new AsciiSlugger())->slug($program->getDisplayShortName())->lower()->toString();

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
