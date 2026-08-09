<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Program;
use App\Entity\SkillLevel;
use App\Form\SkillLevelType;
use App\Repository\SkillLevelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Configuration, onglet « Niveaux de compétences » — les niveaux partagés, pas ceux propres à une formation (voir App\Controller\Program\SettingsSkillLevelController).
 *
 * Split out of the former SettingsStructureController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SkillLevelController extends AbstractController
{
    use SettingsTabTrait;

    // Formerly a tab on SettingsInternshipController's "Livret Alternant" page - moved here since
    // it's a rarely-changes-between-years setting, not tied to this year's Livret Alternant
    // content (see App\Entity\SkillLevel::isGlobal() for the program-level opt-out this
    // establishment-wide default list backs).
    #[Route(path: '/settings/structure/skill-levels', name: 'app_settings_structure_skill_levels')]
    public function skillLevelsTab(): Response
    {
        return $this->renderTab('skill_levels');
    }

    #[Route(path: '/settings/structure/skill-levels/new', name: 'app_settings_structure_skill_levels_new')]
    #[Route(path: '/settings/structure/skill-levels/{id}/edit', name: 'app_settings_structure_skill_levels_edit')]
    public function skillLevelForm(Request $request, EntityManagerInterface $entityManager, SkillLevelRepository $repository, ?int $id = null): Response
    {
        $skillLevel = null !== $id ? $this->findGlobalSkillLevelOrNotFound($repository, $id) : null;
        $isEdit = null !== $skillLevel;

        $form = $this->createForm(SkillLevelType::class, $skillLevel ?? new SkillLevel());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillLevelUpdatedFlashMessage' : 'skillLevelCreatedFlashMessage');

            return $this->redirectToRoute('app_settings_structure_skill_levels');
        }

        return $this->render('settings/skill_level_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
        ]);
    }

    #[Route(path: '/settings/structure/skill-levels/{id}/deactivate', name: 'app_settings_structure_skill_levels_deactivate', methods: ['POST'])]
    public function deactivateSkillLevel(Request $request, EntityManagerInterface $entityManager, SkillLevelRepository $repository, int $id): JsonResponse
    {
        $skillLevel = $this->findGlobalSkillLevelOrNotFound($repository, $id);
        $this->assertValidDeactivateToken($request);

        $skillLevel->setInactiveDate(new \DateTimeImmutable());
        $skillLevel->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/settings/structure/skill-levels/data', name: 'app_settings_structure_skill_levels_data')]
    public function skillLevelsData(Request $request, SkillLevelRepository $repository): JsonResponse
    {
        [$draw, $start, $length, $search, $includeInactive] = $this->readDataTableParams($request);

        $total = $repository->countAllGlobal(null, $includeInactive);
        $filteredTotal = '' !== $search ? $repository->countAllGlobal($search, $includeInactive) : $total;
        $rows = $repository->findPageGlobalOrderedByMostRecent($start, $length, '' !== $search ? $search : null, $includeInactive);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filteredTotal,
            'data' => array_map(
                fn (SkillLevel $skillLevel): array => [
                    'id' => $skillLevel->getId(),
                    'isInactive' => null !== $skillLevel->getInactiveDate(),
                    'label' => $skillLevel->getLabel(),
                    'color' => $skillLevel->getColor(),
                    'orderIndex' => $skillLevel->getOrderIndex(),
                    'creationDate' => $skillLevel->getCreationDate()->format('d/m/Y H:i'),
                    'inactiveDate' => $skillLevel->getInactiveDate()?->format('d/m/Y H:i') ?? '—',
                    'createdByName' => $this->userLabel($skillLevel->getCreatedBy()),
                    'inactivatedByName' => $this->userLabel($skillLevel->getInactivatedBy()),
                    'lastUpdatedByName' => $this->userLabel($skillLevel->getLastUpdatedBy()),
                    'lastUpdatedDate' => $skillLevel->getLastUpdatedDate()?->format('d/m/Y H:i') ?? '—',
                ],
                $rows,
            ),
        ]);
    }

    // Unlike findOrNotFound() above, SkillLevel rows aren't all fair game here - a
    // Program-scoped level (see SkillLevel::isGlobal()) must not be editable/
    // deactivatable from this establishment-wide screen.
    private function findGlobalSkillLevelOrNotFound(SkillLevelRepository $repository, int $id): SkillLevel
    {
        $skillLevel = $repository->find($id) ?? throw $this->createNotFoundException();

        if (!$skillLevel->isGlobal()) {
            throw $this->createNotFoundException();
        }

        return $skillLevel;
    }
}
