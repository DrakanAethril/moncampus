<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Service\AttendanceSheetExporter;
use App\Service\ClassListCsvExporter;
use App\Service\ClassRoster;
use App\Service\GotenbergUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The « Exporter » button of the two class lists: an émargement sheet to print and sign, and the
 * list itself as CSV.
 *
 * Staff and admin only, like the two screens the button sits on - those lists hold the class's
 * people, which is the establishment's own directory rather than a teaching tool (see the nav's own
 * comment on them). The `IsGranted` says *who*; App\Enum\Feature::ClassListExports says whether the
 * establishment runs the exports at all, and the two are not the same question: a staff member of
 * an establishment that has not switched them on gets a 404, not a 403.
 *
 * Its own controller rather than four more actions on the already fat ProgramController: the two
 * lists are one screen each, and this is a third thing - a file-handing tool with its own guard.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::ClassListExports)]
class ProgramPeopleExportController extends AbstractController
{
    public function __construct(
        private readonly ClassListCsvExporter $csvExporter,
        private readonly AttendanceSheetExporter $attendanceSheetExporter,
        private readonly ClassRoster $roster,
        private readonly SluggerInterface $slugger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // GET, not POST: these hand back a file rather than changing anything, and a POST handled by
    // Turbo would have to redirect.
    #[Route(path: '/programs/{id}/students/list.csv', name: 'app_program_students_csv', methods: ['GET'])]
    public function studentsCsv(int $id, ProgramRepository $repository, ProgramStudentOptionRepository $optionRepository, ProgramStudentModalityRepository $modalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->csvResponse(
            $this->csvExporter->students(
                $this->roster->ordered($program->getStudents()->toArray()),
                $optionRepository->findOptionsByStudentForProgram($program),
                $modalityRepository->findModalitiesByStudentForProgram($program),
            ),
            $this->filename($program, 'etudiants', 'csv'),
        );
    }

    #[Route(path: '/programs/{id}/teachers/list.csv', name: 'app_program_teachers_csv', methods: ['GET'])]
    public function teachersCsv(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->csvResponse(
            $this->csvExporter->teachers($this->roster->ordered($program->getTeachers()->toArray())),
            $this->filename($program, 'enseignants', 'csv'),
        );
    }

    #[Route(path: '/programs/{id}/students/attendance-sheet.pdf', name: 'app_program_students_attendance_pdf', methods: ['GET'])]
    public function studentsAttendanceSheet(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->attendanceSheet($program, $this->roster->ordered($program->getStudents()->toArray()), 'etudiants', 'app_program_students');
    }

    #[Route(path: '/programs/{id}/teachers/attendance-sheet.pdf', name: 'app_program_teachers_attendance_pdf', methods: ['GET'])]
    public function teachersAttendanceSheet(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->attendanceSheet($program, $this->roster->ordered($program->getTeachers()->toArray()), 'enseignants', 'app_program_teachers');
    }

    /** @param list<User> $people */
    private function attendanceSheet(Program $program, array $people, string $kind, string $backRoute): Response
    {
        // The footer band and the PDF's own <title> name the document; the sheet's heading is
        // translated inside the template. Both go through the same key.
        $title = sprintf('%s — %s', $this->translator->trans('attendanceSheetDocumentTitle'), $program->getDisplayShortName());

        try {
            $pdf = $this->attendanceSheetExporter->export(
                $program,
                array_map($this->roster->documentName(...), $people),
                $title,
                $this->renderView(...),
                new \DateTimeImmutable('today'),
            );
        } catch (GotenbergUnavailableException) {
            // Same handling as the other Gotenberg exports: the screen says so and stays where it
            // was, rather than answering a 500 whose cause is a container being restarted.
            $this->addFlash('error', 'attendanceSheetPdfExportFailedFlashMessage');

            return $this->redirectToRoute($backRoute, ['id' => $program->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->filename($program, 'emargement-'.$kind, 'pdf')),
        ]);
    }

    private function csvResponse(string $csv, string $filename): Response
    {
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ]);
    }

    private function filename(Program $program, string $kind, string $extension): string
    {
        return sprintf('%s-%s.%s', $kind, (string) $this->slugger->slug($program->getDisplayShortName())->lower(), $extension);
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }
}
