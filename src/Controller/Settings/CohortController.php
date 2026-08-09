<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Cohort;
use App\Form\CohortType;
use App\Repository\CohortRepository;
use App\Service\NameColorGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Classes ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class CohortController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/cohorts', name: 'app_settings_structure_cohorts')]
    public function cohortsTab(): Response
    {
        return $this->renderTab('cohorts');
    }

    #[Route(path: '/settings/structure/cohorts/new', name: 'app_settings_structure_cohorts_new')]
    #[Route(path: '/settings/structure/cohorts/{id}/edit', name: 'app_settings_structure_cohorts_edit')]
    public function cohortForm(Request $request, EntityManagerInterface $entityManager, CohortRepository $repository, NameColorGenerator $nameColorGenerator, ?int $id = null): Response
    {
        $cohort = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $cohort;

        // Cohorts predating the color column have none stored - prefill the picker with the
        // generated fallback the dashboards use for them, so the form never silently saves the
        // <input type="color"> widget's black default over that effective color.
        if (null !== $cohort && null === $cohort->getColor()) {
            $cohort->setColor($nameColorGenerator->generateHex($cohort->getName()));
        }

        $form = $this->createForm(CohortType::class, $cohort);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'cohortUpdatedFlashMessage' : 'cohortCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_cohorts');
        }

        return $this->render('settings/cohort_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/cohorts/{id}/deactivate', name: 'app_settings_structure_cohorts_deactivate', methods: ['POST'])]
    public function deactivateCohort(Request $request, EntityManagerInterface $entityManager, CohortRepository $repository, int $id): JsonResponse
    {
        $cohort = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $cohort->setInactiveDate(new \DateTimeImmutable());
        $cohort->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/cohorts/data', name: 'app_settings_structure_cohorts_data')]
    public function cohortsData(Request $request, CohortRepository $repository): JsonResponse
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
                fn (Cohort $cohort): array => [
                    'id' => $cohort->getId(),
                    'isInactive' => null !== $cohort->getInactiveDate(),
                    'name' => $cohort->getName(),
                    'slug' => $cohort->getSlug(),
                    'trackName' => $cohort->getTrack()->getName(),
                    'color' => $cohort->getColor() ?? '—',
                    'ldapGroupName' => $cohort->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $cohort->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $cohort->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($cohort->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($cohort->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($cohort->getLastUpdatedBy()),
                    'lastUpdatedDate' => $cohort->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
