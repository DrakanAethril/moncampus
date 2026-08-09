<?php

namespace App\Controller\Settings;

use App\Entity\Option;
use App\Form\OptionType;
use App\Repository\OptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Options ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class OptionController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/structure/options', name: 'app_settings_structure_options')]
    public function optionsTab(): Response
    {
        return $this->renderTab('options');
    }

    #[Route(path: '/settings/structure/options/new', name: 'app_settings_structure_options_new')]
    #[Route(path: '/settings/structure/options/{id}/edit', name: 'app_settings_structure_options_edit')]
    public function optionForm(Request $request, EntityManagerInterface $entityManager, OptionRepository $repository, ?int $id = null): Response
    {
        $option = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $option;

        $form = $this->createForm(OptionType::class, $option);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'optionUpdatedFlashMessage' : 'optionCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_options');
        }

        return $this->render('settings/option_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/options/{id}/deactivate', name: 'app_settings_structure_options_deactivate', methods: ['POST'])]
    public function deactivateOption(Request $request, EntityManagerInterface $entityManager, OptionRepository $repository, int $id): JsonResponse
    {
        $option = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $option->setInactiveDate(new \DateTimeImmutable());
        $option->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/options/data', name: 'app_settings_structure_options_data')]
    public function optionsData(Request $request, OptionRepository $repository): JsonResponse
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
                fn (Option $option): array => [
                    'id' => $option->getId(),
                    'isInactive' => null !== $option->getInactiveDate(),
                    'name' => $option->getName(),
                    'shortName' => $option->getShortName(),
                    'slug' => $option->getSlug(),
                    'color' => $option->getColor(),
                    'ldapGroupName' => $option->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $option->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $option->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($option->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($option->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($option->getLastUpdatedBy()),
                    'lastUpdatedDate' => $option->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
