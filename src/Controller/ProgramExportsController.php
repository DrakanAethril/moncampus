<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Form\ExportDateRangeType;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The per-program "Exports" page reached via the Section > Année scolaire > Classe nav menu -
// staff/admin only, same reasoning as ProgramReportingController. Each tab is a one-off
// generate-on-submit tool (not persisted, unlike the "Comptes rendus" settings tab): pick some
// parameters, get a printable/reviewable result back on the same page.
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ProgramExportsController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/exports', name: 'app_program_exports')]
    #[Route(path: '/programs/{id}/exports/signature', name: 'app_program_exports_signature')]
    public function signature(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, ProgramStudentOptionRepository $studentOptionRepository, ProgramStudentModalityRepository $studentModalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $form = $this->createForm(ExportDateRangeType::class);
        $form->handleRequest($request);

        $sheets = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $sessions = $lessonSessionRepository->findForProgramBetween($program, $form->get('startDay')->getData(), $form->get('endDay')->getData());
            $sheets = $this->buildSignatureSheets($program, $sessions, $studentOptionRepository, $studentModalityRepository);
        }

        return $this->render('program/exports.html.twig', [
            'program' => $program,
            'activeTab' => 'signature',
            'form' => $form,
            'sheets' => $sheets,
        ]);
    }

    #[Route(path: '/programs/{id}/exports/invoicing', name: 'app_program_exports_invoicing')]
    public function invoicing(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isFinancialManagementEnabled());
        $form = $this->createForm(ExportDateRangeType::class);
        $form->handleRequest($request);

        $invoicingRows = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $sessions = $lessonSessionRepository->findForProgramBetween($program, $form->get('startDay')->getData(), $form->get('endDay')->getData());
            $invoicingRows = $this->buildInvoicingRows($sessions);
        }

        return $this->render('program/exports.html.twig', [
            'program' => $program,
            'activeTab' => 'invoicing',
            'form' => $form,
            'invoicingRows' => $invoicingRows,
        ]);
    }

    /**
     * @param list<LessonSession> $sessions
     *
     * @return list<array{optionLabel: ?string, day: string, sessions: list<array>, students: list<User>}>
     */
    private function buildSignatureSheets(Program $program, array $sessions, ProgramStudentOptionRepository $studentOptionRepository, ProgramStudentModalityRepository $studentModalityRepository): array
    {
        $formatSession = static fn (LessonSession $session): array => [
            'startHour' => $session->getStartHour()->format('H:i'),
            'endHour' => $session->getEndHour()->format('H:i'),
            'title' => $session->getDisplayName(),
            'teacherName' => null !== $session->getTeacher() ? ($session->getTeacher()->getDisplayName() ?? $session->getTeacher()->getUsername()) : '—',
        ];

        // Signature sheets are apprenticeship paperwork: only the students following the program's
        // alternance modality (Modality::$isAlternance) belong on them, never the initial-training
        // students sitting in the very same sessions.
        $students = $this->alternanceStudents($program, $studentModalityRepository);

        // A sheet nobody has to sign is a blank page, so a program running no alternance at all
        // exports nothing rather than one empty sheet per day.
        if ([] === $students) {
            return [];
        }

        if ($program->getOptions()->isEmpty()) {
            $sessionsByDay = [];
            foreach ($sessions as $session) {
                $sessionsByDay[$session->getDay()->format('d/m/Y')][] = $formatSession($session);
            }

            $sheets = [];
            foreach ($sessionsByDay as $day => $daySessions) {
                $sheets[] = ['optionLabel' => null, 'day' => $day, 'sessions' => $daySessions, 'students' => $students];
            }

            return $sheets;
        }

        $commonSessionsByDay = [];
        $sessionsByOptionAndDay = [];
        foreach ($sessions as $session) {
            $day = $session->getDay()->format('d/m/Y');
            $formatted = $formatSession($session);

            if ($session->getOptions()->isEmpty()) {
                $commonSessionsByDay[$day][] = $formatted;
            } else {
                foreach ($session->getOptions() as $option) {
                    $sessionsByOptionAndDay[$option->getId()][$day][] = $formatted;
                }
            }
        }

        $studentsByOptionId = [];
        foreach ($students as $student) {
            foreach ($studentOptionRepository->findOptionsForStudent($program, $student) as $option) {
                $studentsByOptionId[$option->getId()][] = $student;
            }
        }

        $sheets = [];
        foreach ($program->getOptions() as $option) {
            $daysForOption = array_unique(array_merge(array_keys($commonSessionsByDay), array_keys($sessionsByOptionAndDay[$option->getId()] ?? [])));

            $optionStudents = $studentsByOptionId[$option->getId()] ?? [];
            if ([] === $optionStudents) {
                continue;
            }

            foreach ($daysForOption as $day) {
                $daySessions = array_merge($commonSessionsByDay[$day] ?? [], $sessionsByOptionAndDay[$option->getId()][$day] ?? []);

                $sheets[] = [
                    'optionLabel' => $option->getShortName(),
                    'day' => $day,
                    'sessions' => $daySessions,
                    'students' => $optionStudents,
                ];
            }
        }

        return $sheets;
    }

    /**
     * The program's students who follow its alternance modality, in the program's own order.
     *
     * @return list<User>
     */
    private function alternanceStudents(Program $program, ProgramStudentModalityRepository $studentModalityRepository): array
    {
        $alternanceStudentIds = $studentModalityRepository->findAlternanceStudentIdsForProgram($program);

        $students = [];
        foreach ($program->getStudents() as $student) {
            $studentId = $student->getId();
            if (null !== $studentId && isset($alternanceStudentIds[$studentId])) {
                $students[] = $student;
            }
        }

        return $students;
    }

    /**
     * @param list<LessonSession> $sessions
     *
     * @return list<array{teacherName: string, volume: float, detail: list<string>}>
     */
    private function buildInvoicingRows(array $sessions): array
    {
        $rowsByTeacherId = [];

        foreach ($sessions as $session) {
            $teacher = $session->getTeacher();
            $key = $teacher?->getId() ?? 0;
            $hours = ($session->getEndHour()->getTimestamp() - $session->getStartHour()->getTimestamp()) / 3600;

            if (!isset($rowsByTeacherId[$key])) {
                $rowsByTeacherId[$key] = [
                    'teacherName' => null !== $teacher ? ($teacher->getDisplayName() ?? $teacher->getUsername()) : '—',
                    'volume' => 0.0,
                    'detail' => [],
                ];
            }

            $rowsByTeacherId[$key]['volume'] += $hours;
            $rowsByTeacherId[$key]['detail'][] = sprintf('%s - %s - %sH', $session->getDay()->format('d/m/Y'), $session->getDisplayName(), $hours);
        }

        return array_values($rowsByTeacherId);
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }
}
