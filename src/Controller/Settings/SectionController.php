<?php

namespace App\Controller\Settings;

use App\Entity\Section;
use App\Form\SectionType;
use App\Repository\SectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Sections ».
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SectionController extends AbstractController
{
    use SettingsTabTrait;

    #[Route(path: '/settings/configuration', name: 'app_settings_configuration')]
    #[Route(path: '/settings/structure/sections', name: 'app_settings_structure_sections')]
    public function sectionsTab(): Response
    {
        return $this->renderTab('sections');
    }

    #[Route(path: '/settings/structure/sections/new', name: 'app_settings_structure_sections_new')]
    #[Route(path: '/settings/structure/sections/{id}/edit', name: 'app_settings_structure_sections_edit')]
    public function sectionForm(Request $request, EntityManagerInterface $entityManager, SectionRepository $repository, ?int $id = null): Response
    {
        $section = null !== $id ? $this->findOrNotFound($repository, $id) : null;
        $isEdit = null !== $section;

        $form = $this->createForm(SectionType::class, $section);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'sectionUpdatedFlashMessage' : 'sectionCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_sections');
        }

        return $this->render('settings/section_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/sections/{id}/deactivate', name: 'app_settings_structure_sections_deactivate', methods: ['POST'])]
    public function deactivateSection(Request $request, EntityManagerInterface $entityManager, SectionRepository $repository, int $id): JsonResponse
    {
        $section = $this->findOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $section->setInactiveDate(new \DateTimeImmutable());
        $section->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/sections/data', name: 'app_settings_structure_sections_data')]
    public function sectionsData(Request $request, SectionRepository $repository): JsonResponse
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
                fn (Section $section): array => [
                    'id' => $section->getId(),
                    'isInactive' => null !== $section->getInactiveDate(),
                    'name' => $section->getName(),
                    'slug' => $section->getSlug(),
                    'ldapGroupName' => $section->getLdapGroup()?->getName() ?? '—',
                    'creationDate' => $section->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $section->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($section->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($section->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($section->getLastUpdatedBy()),
                    'lastUpdatedDate' => $section->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }
}
