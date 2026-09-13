<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Accommodation;
use App\Form\AccommodationType;
use App\Repository\AccommodationRepository;
use App\Service\Accommodation\ExtraTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Aménagements ».
 *
 * The catalogue only - who holds which accommodation is granted one account at a time from the
 * annuaire fiche (App\Controller\DirectoryUserController::edit()).
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class AccommodationController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/accommodations', name: 'app_settings_structure_accommodations')]
    public function accommodationsTab(): Response
    {
        return $this->renderTab('accommodations');
    }

    #[Route(path: '/settings/structure/accommodations/new', name: 'app_settings_structure_accommodations_new')]
    #[Route(path: '/settings/structure/accommodations/{id}/edit', name: 'app_settings_structure_accommodations_edit')]
    public function accommodationForm(Request $request, EntityManagerInterface $entityManager, AccommodationRepository $repository, ?int $id = null): Response
    {
        $accommodation = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $accommodation;

        $form = $this->createForm(AccommodationType::class, $accommodation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'accommodationUpdatedFlashMessage' : 'accommodationCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_accommodations');
        }

        return $this->render('settings/accommodation_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    // Deactivation, not deletion, exactly like every other row of this screen - and it matters more
    // here: an accommodation a student held is part of why a copy was timed the way it was, and the
    // attempts that were taken under it keep their own record of the time granted
    // (App\Entity\QuizAttempt::$extraTimePercent). A deactivated row simply stops being offered on
    // the fiche and stops counting towards anybody's profile.
    #[Route(path: '/settings/structure/accommodations/{id}/deactivate', name: 'app_settings_structure_accommodations_deactivate', methods: ['POST'])]
    public function deactivateAccommodation(Request $request, EntityManagerInterface $entityManager, AccommodationRepository $repository, int $id): JsonResponse
    {
        $accommodation = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $accommodation->setInactiveDate(new \DateTimeImmutable());
        $accommodation->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/accommodations/data', name: 'app_settings_structure_accommodations_data')]
    public function accommodationsData(Request $request, AccommodationRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAll(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAll($search, $includeInactive) : $total;
        $rows = $repository->findPageOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (Accommodation $accommodation): array => [
                    'id' => $accommodation->getId(),
                    'isInactive' => null !== $accommodation->getInactiveDate(),
                    'name' => $accommodation->getName(),
                    'quizExtraTimePercent' => null === $accommodation->getQuizExtraTimePercent()
                        ? '—'
                        : ExtraTime::percentLabel((float) $accommodation->getQuizExtraTimePercent()),
                ],
                $rows,
            ),
        ]);
    }
}
