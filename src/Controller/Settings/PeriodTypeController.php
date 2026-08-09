<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Period;
use App\Entity\PeriodType;
use App\Form\PeriodTypeType;
use App\Repository\PeriodTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Types de période ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class PeriodTypeController extends AbstractController
{
    use SettingsTabTrait;

    // Establishment-wide lookup of what kind of Period this is (Scolaire/Entreprise/Vacances) -
    // rarely changes between years, same tier as lesson types/skill levels.
    #[Route(path: '/settings/structure/period-types', name: 'app_settings_structure_period_types')]
    public function periodTypesTab(): Response
    {
        return $this->renderTab('period_types');
    }

    #[Route(path: '/settings/structure/period-types/new', name: 'app_settings_structure_period_types_new')]
    #[Route(path: '/settings/structure/period-types/{id}/edit', name: 'app_settings_structure_period_types_edit')]
    public function periodTypeForm(Request $request, EntityManagerInterface $entityManager, PeriodTypeRepository $repository, ?int $id = null): Response
    {
        $periodType = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $periodType;

        $form = $this->createForm(PeriodTypeType::class, $periodType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'periodTypeUpdatedFlashMessage' : 'periodTypeCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_period_types');
        }

        return $this->render('settings/period_type_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/period-types/{id}/deactivate', name: 'app_settings_structure_period_types_deactivate', methods: ['POST'])]
    public function deactivatePeriodType(Request $request, EntityManagerInterface $entityManager, PeriodTypeRepository $repository, int $id): JsonResponse
    {
        $periodType = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $periodType->setInactiveDate(new \DateTimeImmutable());
        $periodType->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/period-types/data', name: 'app_settings_structure_period_types_data')]
    public function periodTypesData(Request $request, PeriodTypeRepository $repository): JsonResponse
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
                fn (PeriodType $periodType): array => [
                    'id' => $periodType->getId(),
                    'isInactive' => null !== $periodType->getInactiveDate(),
                    'name' => $periodType->getName(),
                    'color' => $periodType->getColor(),
                    'creationDate' => $periodType->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $periodType->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($periodType->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($periodType->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($periodType->getLastUpdatedBy()),
                    'lastUpdatedDate' => $periodType->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
