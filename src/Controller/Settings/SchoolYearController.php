<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\SchoolYear;
use App\Form\SchoolYearType;
use App\Repository\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Pédagogique, onglet « Années scolaires ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SchoolYearController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/teaching', name: 'app_settings_pedagogique')]
    #[Route(path: '/settings/structure/school-years', name: 'app_settings_structure_school_years')]
    public function schoolYearsTab(): Response
    {
        return $this->renderTab('school_years');
    }

    #[Route(path: '/settings/structure/school-years/new', name: 'app_settings_structure_school_years_new')]
    #[Route(path: '/settings/structure/school-years/{id}/edit', name: 'app_settings_structure_school_years_edit')]
    public function schoolYearForm(Request $request, EntityManagerInterface $entityManager, SchoolYearRepository $repository, ?int $id = null): Response
    {
        $schoolYear = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $schoolYear;

        $form = $this->createForm(SchoolYearType::class, $schoolYear);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'schoolYearUpdatedFlashMessage' : 'schoolYearCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_school_years');
        }

        return $this->render('settings/school_year_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/school-years/{id}/deactivate', name: 'app_settings_structure_school_years_deactivate', methods: ['POST'])]
    public function deactivateSchoolYear(Request $request, EntityManagerInterface $entityManager, SchoolYearRepository $repository, int $id): JsonResponse
    {
        $schoolYear = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $schoolYear->setInactiveDate(new \DateTimeImmutable());
        $schoolYear->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/school-years/data', name: 'app_settings_structure_school_years_data')]
    public function schoolYearsData(Request $request, SchoolYearRepository $repository): JsonResponse
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
                fn (SchoolYear $schoolYear): array => [
                    'id' => $schoolYear->getId(),
                    'isInactive' => null !== $schoolYear->getInactiveDate(),
                    'startDate' => $schoolYear->getStartDate()->format('d/m/Y'),
                    'endDate' => $schoolYear->getEndDate()->format('d/m/Y'),
                    'creationDate' => $schoolYear->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $schoolYear->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($schoolYear->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($schoolYear->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($schoolYear->getLastUpdatedBy()),
                    'lastUpdatedDate' => $schoolYear->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
