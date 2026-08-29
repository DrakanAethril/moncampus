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
     * Serves the « Emploi du temps » document back to the tab that uploaded it.
     *
     * Deliberately *not* modelled on app_program_alternance_calendar_pdf: that one is published to
     * an audience and reads a VisibilityLevel, while this document has no audience at all. It
     * inherits the class-level guard, which is the whole rule - whoever may open the tab may open
     * the file, and nobody else.
     */
    #[Route(path: '/ufa/programs/{id}/documents/timetable/pdf', name: 'app_ufa_formation_timetable_document_pdf')]
    public function timetableDocumentPdf(int $id, ProgramRepository $repository, FileUploadService $fileUploadService): Response
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $key = $program->getTimetableDocumentFileKey() ?? throw $this->createNotFoundException();

        return new RedirectResponse($fileUploadService->url($key));
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
