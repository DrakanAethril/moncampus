<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\SkillLevel;
use App\Enum\Feature;
use App\Form\SkillLevelType;
use App\Repository\ProgramRepository;
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
 * Formation > Paramétrage, onglet « Niveaux de compétences » : les niveaux propres à la formation, quand elle a activé l'option ; sinon ceux du Centre de formation (App\Controller\Settings\SkillLevelController) s'appliquent.
 *
 * Split out of the former ProgramSettingsController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::TsfReferential)]
class SettingsSkillLevelController extends AbstractController
{
    use ProgramSettingsTabTrait;

    // The Centre de formation's own shared SkillLevel definition (program IS NULL, see
    // SkillLevel::isGlobal()) - every Program uses this by default, unless it opts into
    // Program::$customSkillLevelsEnabled and gets its own rows managed here instead. Unlike
    // SkillGroup/Skill, SkillLevel has no children entity, so this mirrors the old
    // skill-groups tab shape without a nested skills-list/skillsData equivalent.
    #[Route(path: '/programs/{id}/settings/skill-levels', name: 'app_program_settings_skill_levels')]
    public function skillLevelsTab(int $id, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'skill_levels',
            // Only fetched in the default (non-custom) case, to show the Centre de formation's
            // shared definition read-only - the custom case reads its own rows through the
            // DataTable instead, same as every other tab here.
            'globalSkillLevels' => $program->isCustomSkillLevelsEnabled() ? [] : $skillLevelRepository->findAllActiveGlobal(),
        ]);
    }

    // Flips Program::$customSkillLevelsEnabled - deliberately just a toggle, never a copy: per the
    // product decision behind this feature, switching a Program to custom mode starts from an
    // empty list, the Program must define the whole thing itself rather than fork the Centre de
    // formation's rows. Switching back off doesn't delete anything already entered, in case the
    // Program switches on again later.
    #[Route(path: '/programs/{id}/settings/skill-levels/toggle-custom', name: 'app_program_settings_skill_levels_toggle_custom', methods: ['POST'])]
    public function toggleCustomSkillLevels(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        // A plain HTML form POST (a toggle button, not a DataTable/fetch action) - the token
        // travels in the body like removeFinancialItem()/updateLessonTypeCosts() below, not the
        // X-CSRF-Token header assertValidToken() checks.
        if (!$this->isCsrfTokenValid('program_settings_toggle_custom_skill_levels', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $program->setCustomSkillLevelsEnabled(!$program->isCustomSkillLevelsEnabled());
        $entityManager->flush();

        $this->addFlash('success', $program->isCustomSkillLevelsEnabled() ? 'programCustomSkillLevelsEnabledFlashMessage' : 'programCustomSkillLevelsDisabledFlashMessage');

        return $this->redirectToRoute('app_program_settings_skill_levels', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/skill-levels/new', name: 'app_program_settings_skill_levels_new')]
    #[Route(path: '/programs/{id}/settings/skill-levels/{levelId}/edit', name: 'app_program_settings_skill_levels_edit')]
    public function skillLevelForm(int $id, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository, ?int $levelId = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $this->assertProgramFeatureEnabled($program->isCustomSkillLevelsEnabled());
        $isEdit = null !== $levelId;
        $skillLevel = $isEdit ? $this->findSkillLevelOrNotFound($skillLevelRepository, $program, $levelId) : new SkillLevel('', '#6c757d', $program);

        $form = $this->createForm(SkillLevelType::class, $skillLevel);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entity = $form->getData();
            $this->stampAuditFields($entity, $isEdit);

            $entityManager->persist($entity);
            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'skillLevelUpdatedFlashMessage' : 'skillLevelCreatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_skill_levels', ['id' => $program->getId()]);
        }

        return $this->render('program/skill_level_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'program' => $program,
        ]);
    }

    #[Route(path: '/programs/{id}/settings/skill-levels/{levelId}/deactivate', name: 'app_program_settings_skill_levels_deactivate', methods: ['POST'])]
    public function deactivateSkillLevel(int $id, int $levelId, Request $request, EntityManagerInterface $entityManager, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $skillLevel = $this->findSkillLevelOrNotFound($skillLevelRepository, $program, $levelId);
        $this->assertValidToken('program_settings_deactivate', $request);

        $skillLevel->setInactiveDate(new \DateTimeImmutable());
        $skillLevel->setInactivatedBy($this->currentUser());
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/skill-levels/data', name: 'app_program_settings_skill_levels_data')]
    public function skillLevelsData(int $id, Request $request, ProgramRepository $repository, SkillLevelRepository $skillLevelRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        [$draw, $start, $length, $search, $includeInactive] = $this->readActiveFilterableDataTableParams($request);

        $total = $skillLevelRepository->countAllForProgram($program, null, $includeInactive);
        $filteredTotal = '' !== $search ? $skillLevelRepository->countAllForProgram($program, $search, $includeInactive) : $total;
        $rows = $skillLevelRepository->findPageForProgramOrderedByMostRecent($program, $start, $length, '' !== $search ? $search : null, $includeInactive);

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

    private function findSkillLevelOrNotFound(SkillLevelRepository $repository, Program $program, int $levelId): SkillLevel
    {
        $skillLevel = $repository->find($levelId) ?? throw $this->createNotFoundException();

        if ($skillLevel->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $skillLevel;
    }
}
