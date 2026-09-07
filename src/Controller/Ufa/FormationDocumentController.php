<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Form\UfaAlternanceCalendarType;
use App\Form\UfaTimetableDocumentType;
use App\Repository\ProgramRepository;
use App\Service\FileUploadService;
use App\Service\ProgramPdfReplacer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * UFA > Formations > une formation > « Documents ».
 *
 * A second door onto Program::$alternanceCalendarFileKey, which Paramètres > Pédagogique >
 * Formations already writes. It exists because the two menus serve two different people: the UFA
 * team keeps the alternance calendars up to date and has no business in the formation's whole
 * settings sheet, which carries the cohort, the school year, the visibility tiers and the rest.
 *
 * **The upload itself is not reimplemented**: App\Service\ProgramPdfReplacer holds the store /
 * persist / delete ordering, and both screens call it. What is narrower here is the form - one
 * field, no mode selector (App\Form\UfaAlternanceCalendarType says why) - and the guard: this tab
 * follows `ufa_booklet`, the feature the UFA team is delivered, rather than the settings screens'
 * own reach.
 *
 * The tab also carries a second, unrelated document - « Emploi du temps ». That one is *only* a
 * file: no other screen, export or API reads it, and it has no connection to the platform's own
 * timetable (see App\Form\UfaTimetableDocumentType). It is served back by
 * timetableDocumentPdf() below, behind the same guard as the tab, so a document the UFA team
 * uploads for itself cannot be reached by the audiences the alternance calendar is published to.
 *
 * Two independent forms on one screen: each has its own type, so its own block prefix, and
 * `handleRequest()` claims only the one whose name is in the payload. Saving one therefore never
 * validates - nor clears - the other.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class FormationDocumentController extends AbstractController
{
    #[Route(path: '/ufa/programs/{id}/documents', name: 'app_ufa_formation_documents')]
    public function documents(int $id, Request $request, ProgramRepository $repository, ProgramPdfReplacer $pdfReplacer, EntityManagerInterface $entityManager): Response
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        $form = $this->createForm(UfaAlternanceCalendarType::class);
        $form->handleRequest($request);

        $timetableForm = $this->createForm(UfaTimetableDocumentType::class);
        $timetableForm->handleRequest($request);

        if ($timetableForm->isSubmitted() && $timetableForm->isValid()) {
            $replaced = $pdfReplacer->replace(
                $timetableForm->get('timetableDocumentFile')->getData(),
                ProgramPdfReplacer::TIMETABLE_DOCUMENT_PREFIX,
                $program,
                $program->getTimetableDocumentFileKey(),
                $program->setTimetableDocumentFileKey(...),
            );

            if ($replaced) {
                $program->setLastUpdatedBy($this->currentUser());
                $program->setLastUpdatedDate(new \DateTimeImmutable());
                $entityManager->flush();

                $this->addFlash('success', 'ufaFormationTimetableDocumentSavedFlashMessage');
            }

            return $this->redirectToRoute('app_ufa_formation_documents', ['id' => $program->getId()]);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $replaced = $pdfReplacer->replace(
                $form->get('alternanceCalendarFile')->getData(),
                ProgramPdfReplacer::ALTERNANCE_CALENDAR_PREFIX,
                $program,
                $program->getAlternanceCalendarFileKey(),
                $program->setAlternanceCalendarFileKey(...),
            );

            if ($replaced) {
                // Putting a file here *is* the choice of serving it: leaving the mode on Period
                // would store a PDF that the calendar route never reads, and the screen would look
                // like it had done nothing. The formation's own settings sheet is where somebody
                // goes back to the generated calendar.
                $program->setAlternanceCalendarMode(ProgramAlternanceCalendarMode::File);
                $program->setLastUpdatedBy($this->currentUser());
                $program->setLastUpdatedDate(new \DateTimeImmutable());
                $entityManager->flush();

                $this->addFlash('success', 'ufaFormationDocumentsSavedFlashMessage');
            }

            return $this->redirectToRoute('app_ufa_formation_documents', ['id' => $program->getId()]);
        }

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => 'documents',
            'form' => $form,
            'timetableForm' => $timetableForm,
        ]);
    }

    /**
     * Shows one of the tab's two documents inside the application rather than handing the browser a
     * bare file.
     *
     * The tab used to link straight at the PDF. That opens a new tab on a route which immediately
     * redirects to a signed storage URL, so the reader lands on a page with no application around
     * it and, because the only entry in that tab's history is a redirect back onto the same file,
     * no way back either - « revenir en arrière » simply replays the redirect. Framing the document
     * in an ordinary screen gives it a title, the breadcrumb it hangs off, and a history entry that
     * behaves like every other.
     *
     * One route for both documents: they differ by which column they read and how they are named,
     * and nothing else.
     */
    #[Route(path: '/ufa/programs/{id}/documents/{document}/view', name: 'app_ufa_formation_document_view', requirements: ['document' => 'calendar|timetable'])]
    public function documentView(int $id, string $document, ProgramRepository $repository): Response
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $isCalendar = 'calendar' === $document;

        if (null === ($isCalendar ? $program->getAlternanceCalendarFileKey() : $program->getTimetableDocumentFileKey())) {
            throw $this->createNotFoundException();
        }

        return $this->render('ufa/formation/document_view.html.twig', [
            'program' => $program,
            'heading' => $isCalendar ? 'programAlternanceCalendarNavLabel' : 'ufaFormationTimetableDocumentHeading',
            'frameUrl' => $this->generateUrl(
                $isCalendar ? 'app_ufa_formation_calendar_document_pdf' : 'app_ufa_formation_timetable_document_pdf',
                ['id' => $program->getId()],
            ),
        ]);
    }

    /**
     * Serves the alternance calendar *file* back to the tab that uploaded it.
     *
     * Deliberately *not* app_program_alternance_calendar_pdf, which is the published door: that one
     * is gated on the `my_alternance` feature and on the formation's own visibility tiers, and in
     * Period mode it answers with the calendar it generates rather than with the file. This tab
     * shows what was deposited in it, to whoever may open the tab - the class-level guard is the
     * whole rule - so it reads the column directly.
     */
    #[Route(path: '/ufa/programs/{id}/documents/calendar/pdf', name: 'app_ufa_formation_calendar_document_pdf')]
    public function calendarDocumentPdf(int $id, ProgramRepository $repository, FileUploadService $fileUploadService, TranslatorInterface $translator): Response
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        return $this->serveDocument(
            $program->getAlternanceCalendarFileKey(),
            'programAlternanceCalendarDocumentFilename',
            $program,
            $fileUploadService,
            $translator,
        );
    }

    /**
     * Serves the « Emploi du temps » document back to the tab that uploaded it.
     *
     * Deliberately *not* modelled on app_program_alternance_calendar_pdf: that one is published to
     * an audience and reads a VisibilityLevel, while this document has no audience at all. It
     * inherits the class-level guard, which is the whole rule - whoever may open the tab may open
     * the file, and nobody else.
     */
    #[Route(path: '/ufa/programs/{id}/documents/timetable/pdf', name: 'app_ufa_formation_timetable_document_pdf')]
    public function timetableDocumentPdf(int $id, ProgramRepository $repository, FileUploadService $fileUploadService, TranslatorInterface $translator): Response
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();

        return $this->serveDocument(
            $program->getTimetableDocumentFileKey(),
            'programTimetableDocumentFilename',
            $program,
            $fileUploadService,
            $translator,
        );
    }

    /**
     * An uploaded document carries no name of its own - the key is a timestamp - so the formation
     * lends it one on the way out (see App\Service\DownloadFilename).
     */
    private function serveDocument(?string $key, string $filenameKey, Program $program, FileUploadService $fileUploadService, TranslatorInterface $translator): Response
    {
        $key ?? throw $this->createNotFoundException();

        return new RedirectResponse($fileUploadService->downloadUrl(
            $key,
            $translator->trans($filenameKey, ['%program%' => $program->getShortName()]),
        ));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
