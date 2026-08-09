<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Modality;
use App\Form\ModalityType;
use App\Repository\ModalityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Modalités ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class ModalityController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/modalities', name: 'app_settings_structure_modalities')]
    public function modalitiesTab(): Response
    {
        return $this->renderTab('modalities');
    }

    #[Route(path: '/settings/structure/modalities/new', name: 'app_settings_structure_modalities_new')]
    #[Route(path: '/settings/structure/modalities/{id}/edit', name: 'app_settings_structure_modalities_edit')]
    public function modalityForm(Request $request, EntityManagerInterface $entityManager, ModalityRepository $repository, ?int $id = null): Response
    {
        $modality = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $modality;

        $form = $this->createForm(ModalityType::class, $modality);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'modalityUpdatedFlashMessage' : 'modalityCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_modalities');
        }

        return $this->render('settings/modality_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/modalities/{id}/deactivate', name: 'app_settings_structure_modalities_deactivate', methods: ['POST'])]
    public function deactivateModality(Request $request, EntityManagerInterface $entityManager, ModalityRepository $repository, int $id): JsonResponse
    {
        $modality = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $modality->setInactiveDate(new \DateTimeImmutable());
        $modality->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/modalities/data', name: 'app_settings_structure_modalities_data')]
    public function modalitiesData(Request $request, ModalityRepository $repository): JsonResponse
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
                fn (Modality $modality): array => [
                    'id' => $modality->getId(),
                    'isInactive' => null !== $modality->getInactiveDate(),
                    'name' => $modality->getName(),
                    'shortName' => $modality->getShortName() ?? '—',
                    'slug' => $modality->getSlug(),
                    'color' => $modality->getColor(),
                    'ldapGroupName' => $modality->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $modality->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $modality->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($modality->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($modality->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($modality->getLastUpdatedBy()),
                    'lastUpdatedDate' => $modality->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
