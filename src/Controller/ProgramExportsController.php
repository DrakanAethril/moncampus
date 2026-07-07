<?php

namespace App\Controller;

use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Form\ExportDateRangeType;
use App\Form\SkillsSelectionType;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
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
    #[Route(path: '/programs/{id}/exports', name: 'app_program_exports')]
    #[Route(path: '/programs/{id}/exports/signature', name: 'app_program_exports_signature')]
    public function signature(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, ProgramStudentOptionRepository $studentOptionRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $form = $this->createForm(ExportDateRangeType::class);
        $form->handleRequest($request);

        $sheets = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $sessions = $lessonSessionRepository->findForProgramBetween($program, $form->get('startDay')->getData(), $form->get('endDay')->getData());
            $sheets = $this->buildSignatureSheets($program, $sessions, $studentOptionRepository);
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

    #[Route(path: '/programs/{id}/exports/tsf', name: 'app_program_exports_tsf')]
    public function tsf(int $id, Request $request, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $form = $this->createForm(SkillsSelectionType::class, null, ['program' => $program]);
        $form->handleRequest($request);

        $skills = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $skills = $form->get('skills')->getData();
        }

        return $this->render('program/exports.html.twig', [
            'program' => $program,
            'activeTab' => 'tsf',
            'form' => $form,
            'skills' => $skills,
        ]);
    }

    /**
     * @param list<LessonSession> $sessions
     *
     * @return list<array{optionLabel: ?string, day: string, sessions: list<array>, students: list<User>}>
     */
    private function buildSignatureSheets(Program $program, array $sessions, ProgramStudentOptionRepository $studentOptionRepository): array
    {
        $formatSession = static fn (LessonSession $session): array => [
            'startHour' => $session->getStartHour()->format('H:i'),
            'endHour' => $session->getEndHour()->format('H:i'),
            'lessonTypeName' => $session->getLessonType()?->getName() ?? '—',
            'title' => $session->getDisplayName(),
            'teacherName' => null !== $session->getTeacher() ? ($session->getTeacher()->getDisplayName() ?? $session->getTeacher()->getUsername()) : '—',
        ];

        if ($program->getOptions()->isEmpty()) {
            $sessionsByDay = [];
            foreach ($sessions as $session) {
                $sessionsByDay[$session->getDay()->format('d/m/Y')][] = $formatSession($session);
            }

            $sheets = [];
            foreach ($sessionsByDay as $day => $daySessions) {
                $sheets[] = ['optionLabel' => null, 'day' => $day, 'sessions' => $daySessions, 'students' => $program->getStudents()->toArray()];
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
        foreach ($program->getStudents() as $student) {
            foreach ($studentOptionRepository->findOptionsForStudent($program, $student) as $option) {
                $studentsByOptionId[$option->getId()][] = $student;
            }
        }

        $sheets = [];
        foreach ($program->getOptions() as $option) {
            $daysForOption = array_unique(array_merge(array_keys($commonSessionsByDay), array_keys($sessionsByOptionAndDay[$option->getId()] ?? [])));

            foreach ($daysForOption as $day) {
                $daySessions = array_merge($commonSessionsByDay[$day] ?? [], $sessionsByOptionAndDay[$option->getId()][$day] ?? []);

                $sheets[] = [
                    'optionLabel' => $option->getShortName(),
                    'day' => $day,
                    'sessions' => $daySessions,
                    'students' => $studentsByOptionId[$option->getId()] ?? [],
                ];
            }
        }

        return $sheets;
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
