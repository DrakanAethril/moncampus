<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Form\UfaAlternanceCalendarType;
use App\Repository\ProgramRepository;
use App\Service\ProgramPdfReplacer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
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
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
