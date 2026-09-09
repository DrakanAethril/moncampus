<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\LessonSession;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\ExportDateRangeType;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Service\FormValue;
use App\Service\GotenbergUnavailableException;
use App\Service\QueryValue;
use App\Service\SignatureSheetExporter;
use App\Service\TsfFicheExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// The per-program "Exports" page reached via the Section > Année scolaire > Classe nav menu -
// staff/admin only, same reasoning as ProgramReportingController. Each tab is a one-off
// generate-on-submit tool (not persisted, unlike the "Comptes rendus" settings tab): pick some
// parameters, get a printable/reviewable result back on the same page.
/**
 * @phpstan-import-type SignatureSheet from SignatureSheetExporter
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::ProgramExports)]
class ProgramExportsController extends AbstractController
{
    use ProgramFeatureGuardTrait;

    #[Route(path: '/programs/{id}/exports', name: 'app_program_exports')]
    #[Route(path: '/programs/{id}/exports/signature', name: 'app_program_exports_signature')]
    public function signature(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, ProgramStudentOptionRepository $studentOptionRepository, ProgramStudentModalityRepository $studentModalityRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $form = $this->createForm(ExportDateRangeType::class, null, ['with_alternance_only' => true]);
        $form->handleRequest($request);

        $sheets = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $sessions = $lessonSessionRepository->findForProgramBetween($program, $form->get('startDay')->getData(), $form->get('endDay')->getData());
            $sheets = $this->buildSignatureSheets($program, $sessions, $studentOptionRepository, $studentModalityRepository, FormValue::bool($form, 'alternanceOnly'));
        }

        return $this->render('program/exports.html.twig', [
            'program' => $program,
            'activeTab' => 'signature',
            'form' => $form,
            'sheets' => $sheets,
        ]);
    }

    /**
     * The same sheets, handed back as a PDF instead of drawn on the page - the tab's « Export PDF »
     * button, which is a second submitter of the very form the « Générer » one submits, so the two
     * read the same date range and the same « alternants seulement » box.
     *
     * A GET, like its TSF neighbour: this hands back a file rather than changing anything, and a
     * POST handled by Turbo would have to redirect. Nothing is stored between two printings - the
     * whole request is its query string.
     */
    #[Route(path: '/programs/{id}/exports/signature.pdf', name: 'app_program_exports_signature_pdf', methods: ['GET'])]
    public function signaturePdf(int $id, Request $request, ProgramRepository $repository, LessonSessionRepository $lessonSessionRepository, ProgramStudentOptionRepository $studentOptionRepository, ProgramStudentModalityRepository $studentModalityRepository, SignatureSheetExporter $exporter, SluggerInterface $slugger, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        $form = $this->createForm(ExportDateRangeType::class, null, ['with_alternance_only' => true]);
        $form->handleRequest($request);

        // Re-read through the form rather than off the query string: an « Export PDF » clicked on a
        // half-filled range must be refused exactly where « Générer » would have refused it.
        $sheets = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $sessions = $lessonSessionRepository->findForProgramBetween($program, $form->get('startDay')->getData(), $form->get('endDay')->getData());
            $sheets = $this->buildSignatureSheets($program, $sessions, $studentOptionRepository, $studentModalityRepository, FormValue::bool($form, 'alternanceOnly'));
        }

        // A file with no page in it is worse than a refusal that says so: the screen keeps its form
        // and its message, rather than handing back an empty PDF.
        if ([] === $sheets) {
            $this->addFlash('danger', 'signatureSheetsPdfEmptyFlashMessage');

            return $this->redirectToRoute('app_program_exports_signature', ['id' => $program->getId()]);
        }

        $title = sprintf('%s — %s', $translator->trans('attendanceSheetDocumentTitle'), $program->getDisplayShortName());

        try {
            $pdf = $exporter->export($program, $sheets, $title, $this->renderView(...), new \DateTimeImmutable('today'));
        } catch (GotenbergUnavailableException) {
            // Same handling as the other Gotenberg exports: the screen says so and stays where it
            // was, rather than answering a 500 whose cause is a container being restarted.
            $this->addFlash('danger', 'signatureSheetsPdfExportFailedFlashMessage');

            return $this->redirectToRoute('app_program_exports_signature', ['id' => $program->getId()]);
        }

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                sprintf('emargement-%s.pdf', (string) $slugger->slug($program->getDisplayShortName())->lower()),
            ),
        ]);
    }

    /**
     * The printed training referential (TSF), one fiche per competency. Gated on the program's own
     * tsfExportEnabled flag, off by default: only the formations that actually keep a referential
     * have anything to print here.
     */
    #[Route(path: '/programs/{id}/exports/tsf', name: 'app_program_exports_tsf')]
    public function tsf(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTsfExportEnabled());

        return $this->render('program/exports.html.twig', [
            'program' => $program,
            'activeTab' => 'tsf',
        ]);
    }

    // A GET, not a POST: this hands back a file rather than changing anything, and a POST handled by
    // Turbo would have to redirect - see the tab's own form.
    #[Route(path: '/programs/{id}/exports/tsf.pdf', name: 'app_program_exports_tsf_pdf')]
    public function tsfPdf(int $id, Request $request, ProgramRepository $repository, TsfFicheExporter $exporter, SluggerInterface $slugger): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isTsfExportEnabled());

        // Re-resolved against the program's own options rather than trusted: an id from the query
        // string is a user's to write.
        $optionId = QueryValue::nullableInt($request, 'option');
        $only = null;
        foreach ($program->getOptions() as $option) {
            if (null !== $optionId && $option->getId() === $optionId) {
                $only = $option;
                break;
            }
        }

        try {
            $pdf = $exporter->export($program, $this->renderView(...), new \DateTimeImmutable('today'), $only);
        } catch (GotenbergUnavailableException) {
            $this->addFlash('danger', 'referentialExportPdfFailedFlashMessage');

            return $this->redirectToRoute('app_program_exports_tsf', ['id' => $program->getId()]);
        }

        $name = sprintf(
            'referentiel-%s%s.pdf',
            (string) $slugger->slug($program->getDisplayShortName())->lower(),
            null !== $only ? '-'.(string) $slugger->slug($only->getShortName())->lower() : '',
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name),
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
     * @return list<SignatureSheet>
     */
    private function buildSignatureSheets(Program $program, array $sessions, ProgramStudentOptionRepository $studentOptionRepository, ProgramStudentModalityRepository $studentModalityRepository, bool $alternanceOnly): array
    {
        $formatSession = static fn (LessonSession $session): array => [
            'startHour' => $session->getStartHour()->format('H:i'),
            'endHour' => $session->getEndHour()->format('H:i'),
            'title' => $session->getDisplayName(),
            'teacherName' => null !== $session->getTeacher() ? ($session->getTeacher()->getDisplayName() ?? $session->getTeacher()->getUsername()) : '—',
        ];

        // Signature sheets are apprenticeship paperwork by default: only the students following the
        // program's alternance modality (Modality::$isAlternance) belong on them, never the
        // initial-training students sitting in the very same sessions. The tab's own checkbox is
        // what widens that to the whole class, for the formations that sign everybody.
        $students = $alternanceOnly
            ? $this->alternanceStudents($program, $studentModalityRepository)
            : array_values($program->getStudents()->toArray());

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
